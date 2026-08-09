<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Delivery;
use VentureDrake\LaravelCrm\Models\DeliveryProduct;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\InvoiceLine;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\OrderProduct;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\TaxRate;
use VentureDrake\LaravelCrm\Services\DeliveryService;
use VentureDrake\LaravelCrm\Services\InvoiceService;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\ListOrders;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\ViewOrder;
use VentureDrake\LaravelCrmFilament\Support\OrderDrawdownPrefill;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * Converting an Order has to produce a document whose header agrees with its
 * own body — and with core's definition of a total.
 *
 * Two ways that went wrong, both covered here:
 *
 *  - `totalsFor()` returned the bare subtotal as the total while `tax` was
 *    copied off the order, so a $110 order became a $100 invoice carrying $10
 *    of tax. That flows into amount_due, the PDF and the portal.
 *  - Convert-to-Delivery on an order with nothing outstanding prefilled no
 *    lines, and the service happily wrote an empty Delivery under a green
 *    success toast.
 */
beforeEach(function () {
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'Conversion Tester',
        'email' => 'conversion-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->assignRole('Owner');
    $this->actingAs($this->user->fresh());

    $this->taxRate = TaxRate::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Standard',
        'rate' => 10,
        'default' => 1,
    ]);

    $this->product = Product::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Widget',
        'active' => true,
        'tax_rate_id' => $this->taxRate->id,
    ]);

    // $100 of goods, $10 of tax, $110 all in. Assigned in dollars because these
    // models' setters run Money::toInteger(); the columns hold cents.
    $this->order = Order::create([
        'external_id' => (string) Str::uuid(),
        'currency' => 'USD',
        'subtotal' => 100,
        'tax' => 10,
        'total' => 110,
    ]);

    $this->orderProduct = OrderProduct::create([
        'external_id' => (string) Str::uuid(),
        'order_id' => $this->order->id,
        'product_id' => $this->product->id,
        'quantity' => 10,
        'price' => 10,
        'amount' => 100,
    ]);
});

it('adds tax into the total it derives from the prefilled lines', function () {
    $products = OrderDrawdownPrefill::invoiceProducts($this->order->fresh());
    $totals = OrderDrawdownPrefill::totalsFor($products);

    expect($totals['sub_total'])->toBe(100.0)
        ->and($totals['tax'])->toBe(10.0)
        ->and($totals['total'])->toBe(110.0);
});

/**
 * Drives a ViewOrder header action without rendering the page.
 *
 * `livewire(ViewOrder::class)` cannot be used here: crm-view-tabs.blade.php
 * trips Livewire's child-tag validation in the test harness, which has nothing
 * to do with conversions. Reflecting into getHeaderActions() is the convention
 * PipelineConversionActionsTest already established for this page.
 */
function callOrderHeaderAction(string $name, Order $record, array $parameters = []): void
{
    $page = (new ReflectionClass(ViewOrder::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(ViewOrder::class, 'getHeaderActions');
    $method->setAccessible(true);

    foreach ($method->invoke($page) as $action) {
        if ($action->getName() === $name) {
            $action->call(array_merge(['record' => $record], $parameters));

            return;
        }
    }

    throw new RuntimeException("ViewOrder has no '{$name}' header action.");
}

it('persists a converted invoice whose total includes its tax', function () {
    callOrderHeaderAction('convertToInvoice', $this->order->fresh(), [
        'invoiceService' => app(InvoiceService::class),
    ]);

    $invoice = Invoice::where('order_id', $this->order->id)->firstOrFail();

    // Stored as integer cents.
    expect((int) $invoice->subtotal)->toBe(10000)
        ->and((int) $invoice->tax)->toBe(1000)
        ->and((int) $invoice->total)->toBe(11000);
});

it('states the tax for the part it is actually billing, not the whole order', function () {
    // Six of the ten already invoiced, so this conversion covers four.
    $priorInvoice = Invoice::create([
        'external_id' => (string) Str::uuid(),
        'order_id' => $this->order->id,
    ]);

    InvoiceLine::create([
        'external_id' => (string) Str::uuid(),
        'invoice_id' => $priorInvoice->id,
        'order_product_id' => $this->orderProduct->id,
        'product_id' => $this->product->id,
        'quantity' => 6,
        'price' => 10,
        'amount' => 60,
    ]);

    $totals = OrderDrawdownPrefill::totalsFor(
        OrderDrawdownPrefill::invoiceProducts($this->order->fresh())
    );

    // 4 x $10 = $40, plus 10% = $44 — not the order's own $10 of tax.
    expect($totals['sub_total'])->toBe(40.0)
        ->and($totals['tax'])->toBe(4.0)
        ->and($totals['total'])->toBe(44.0);
});

it('refuses to create a delivery for an order with nothing outstanding', function () {
    $delivered = Delivery::create([
        'external_id' => (string) Str::uuid(),
        'order_id' => $this->order->id,
    ]);

    DeliveryProduct::create([
        'external_id' => (string) Str::uuid(),
        'delivery_id' => $delivered->id,
        'order_product_id' => $this->orderProduct->id,
        'quantity' => 10,
    ]);

    expect(OrderDrawdownPrefill::hasRemaining($this->order->fresh(), DeliveryProduct::class, 'delivery'))
        ->toBeFalse();

    callOrderHeaderAction('convertToDelivery', $this->order->fresh(), [
        'deliveryService' => app(DeliveryService::class),
    ]);

    // Still just the one delivery — no empty second document.
    expect(Delivery::where('order_id', $this->order->id)->count())->toBe(1);
});

it('applies the same guard to the table row action', function () {
    $delivered = Delivery::create([
        'external_id' => (string) Str::uuid(),
        'order_id' => $this->order->id,
    ]);

    DeliveryProduct::create([
        'external_id' => (string) Str::uuid(),
        'delivery_id' => $delivered->id,
        'order_product_id' => $this->orderProduct->id,
        'quantity' => 10,
    ]);

    livewire(ListOrders::class)
        ->callAction(TestAction::make('convertToDelivery')->table($this->order));

    expect(Delivery::where('order_id', $this->order->id)->count())->toBe(1);
});

it('still converts to a delivery while something is outstanding', function () {
    callOrderHeaderAction('convertToDelivery', $this->order->fresh(), [
        'deliveryService' => app(DeliveryService::class),
    ]);

    $delivery = Delivery::where('order_id', $this->order->id)->firstOrFail();

    expect($delivery->deliveryProducts)->toHaveCount(1)
        ->and((float) $delivery->deliveryProducts->first()->quantity)->toBe(10.0);
});
