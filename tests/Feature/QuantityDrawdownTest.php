<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Delivery;
use VentureDrake\LaravelCrm\Models\DeliveryProduct;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\InvoiceLine;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\OrderProduct;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrmFilament\Support\OrderDrawdownPrefill;
use VentureDrake\LaravelCrmFilament\Support\RemainingQuantity;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

/**
 * Order 10, invoice 6, reopen Order → Invoice: the prefill offers 4, and
 * posting 5 is rejected server-side.
 *
 * Core's QuantityWithinRemaining cannot do this job inside a Filament
 * Repeater — its constructor derives the row index from
 * explode('.', $attribute)[1], which is the literal string `products` there,
 * so the rule silently passes everything. RemainingQuantity runs the same
 * query, reading the row through Filament's $get instead.
 */
beforeEach(function () {
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'Drawdown Tester',
        'email' => 'drawdown-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->assignRole('Owner');
    $this->actingAs($this->user->fresh());

    $this->product = Product::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Widget',
        'active' => true,
    ]);

    $this->order = Order::create(['external_id' => (string) Str::uuid()]);

    $this->orderProduct = OrderProduct::create([
        'external_id' => (string) Str::uuid(),
        'order_id' => $this->order->id,
        'product_id' => $this->product->id,
        'quantity' => 10,
        'price' => 1000,
        'amount' => 10000,
    ]);
});

function drawInvoiceLine(Order $order, OrderProduct $orderProduct, float $quantity): InvoiceLine
{
    $invoice = Invoice::create([
        'external_id' => (string) Str::uuid(),
        'order_id' => $order->id,
    ]);

    return InvoiceLine::create([
        'external_id' => (string) Str::uuid(),
        'invoice_id' => $invoice->id,
        'order_product_id' => $orderProduct->id,
        'product_id' => $orderProduct->product_id,
        'quantity' => $quantity,
        'price' => 1000,
        'amount' => (int) round($quantity * 1000),
    ]);
}

it('reports the full quantity as remaining before anything is drawn', function () {
    expect(RemainingQuantity::forOrderProduct($this->orderProduct, InvoiceLine::class, 'invoice'))
        ->toBe(10.0);
});

it('subtracts everything already invoiced', function () {
    drawInvoiceLine($this->order, $this->orderProduct, 6);

    expect(RemainingQuantity::forOrderProduct($this->orderProduct, InvoiceLine::class, 'invoice'))
        ->toBe(4.0);
});

it('sums across several invoices rather than reading only the first', function () {
    // Core's prefill uses ->first() while its rule uses sum(); following sum()
    // is following the guard rather than the bug.
    drawInvoiceLine($this->order, $this->orderProduct, 3);
    drawInvoiceLine($this->order, $this->orderProduct, 2);

    expect(RemainingQuantity::forOrderProduct($this->orderProduct, InvoiceLine::class, 'invoice'))
        ->toBe(5.0);
});

it('releases quantity held by a soft-deleted invoice', function () {
    $line = drawInvoiceLine($this->order, $this->orderProduct, 6);

    $line->invoice->delete();

    // whereHas($relation) is what stops a deleted document holding quantity
    // hostage forever.
    expect(RemainingQuantity::forOrderProduct($this->orderProduct, InvoiceLine::class, 'invoice'))
        ->toBe(10.0);
});

it('does not count the row being edited against itself', function () {
    $line = drawInvoiceLine($this->order, $this->orderProduct, 6);

    expect(RemainingQuantity::forOrderProduct($this->orderProduct, InvoiceLine::class, 'invoice', $line->id))
        ->toBe(10.0);
});

it('never reports a negative remainder', function () {
    drawInvoiceLine($this->order, $this->orderProduct, 25);

    expect(RemainingQuantity::forOrderProduct($this->orderProduct, InvoiceLine::class, 'invoice'))
        ->toBe(0.0);
});

it('handles fractional drawdowns to three places', function () {
    drawInvoiceLine($this->order, $this->orderProduct, 0.5);
    drawInvoiceLine($this->order, $this->orderProduct, 0.25);

    expect(RemainingQuantity::forOrderProduct($this->orderProduct, InvoiceLine::class, 'invoice'))
        ->toBe(9.25);
});

it('is a no-op for a form that draws nothing down', function () {
    // Quote, order, purchase order and deal lines have no order_product_id to
    // draw against, so the rule must not invent a cap.
    expect(RemainingQuantity::forRow(['order_product_id' => null], InvoiceLine::class, 'invoice', 'invoice_line_id'))
        ->toBeNull();

    expect(RemainingQuantity::forRow(
        ['order_product_id' => $this->orderProduct->id],
        null,
        null,
        null,
    ))->toBeNull();
});

it('clamps a typed quantity down to the remainder', function () {
    expect(RemainingQuantity::clamp(12, 4.0))->toBe(4.0)
        ->and(RemainingQuantity::clamp(3, 4.0))->toBe(3)
        // No cap means no clamp.
        ->and(RemainingQuantity::clamp(12, null))->toBe(12);
});

it('prefills the invoice conversion from the remainder, not the ordered quantity', function () {
    drawInvoiceLine($this->order, $this->orderProduct, 6);

    $products = OrderDrawdownPrefill::invoiceProducts($this->order->fresh());

    expect($products)->toHaveCount(1)
        ->and($products[0]['quantity'])->toBe(4.0)
        ->and($products[0]['order_product_id'])->toBe($this->orderProduct->id)
        // Header totals recomputed from the prefilled lines, so a partial
        // invoice's header matches its own body rather than restating the
        // whole order's total.
        ->and(OrderDrawdownPrefill::totalsFor($products)['sub_total'])
        ->toBe(round($products[0]['unit_price'] * 4, 2))
        ->and($products[0]['amount'])->toBe(round($products[0]['unit_price'] * 4, 2));
});

it('drops fully drawn lines from the prefill rather than offering zero', function () {
    drawInvoiceLine($this->order, $this->orderProduct, 10);

    expect(OrderDrawdownPrefill::invoiceProducts($this->order->fresh()))->toBe([]);
    expect(OrderDrawdownPrefill::hasRemaining($this->order->fresh(), InvoiceLine::class, 'invoice'))->toBeFalse();
});

it('prefills the delivery conversion from the remainder too', function () {
    $delivery = Delivery::create([
        'external_id' => (string) Str::uuid(),
        'order_id' => $this->order->id,
    ]);

    DeliveryProduct::create([
        'external_id' => (string) Str::uuid(),
        'delivery_id' => $delivery->id,
        'order_product_id' => $this->orderProduct->id,
        'quantity' => 2.5,
    ]);

    $products = OrderDrawdownPrefill::deliveryProducts($this->order->fresh());

    expect($products)->toHaveCount(1)
        ->and($products[0]['quantity'])->toBe(7.5);
});

it('shares one implementation between the page action and the table row action', function () {
    // Two copies of drawdown arithmetic is how two surfaces come to disagree.
    $resource = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Resources/Orders/OrderResource.php');
    $action = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Resources/Orders/Pages/Concerns/HasOrderConvertToDeliveryAction.php');

    expect($resource)->toContain('OrderDrawdownPrefill::deliveryProducts')
        ->and($action)->toContain('OrderDrawdownPrefill::deliveryProducts');
});
