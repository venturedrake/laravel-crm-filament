<?php

use Filament\Actions\Action;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\OrderProduct;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\TaxRate;
use VentureDrake\LaravelCrm\Services\InvoiceService;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\ViewOrder;
use VentureDrake\LaravelCrmFilament\Support\FormPayload;
use VentureDrake\LaravelCrmFilament\Support\OrderDrawdownPrefill;

/**
 * Core 2.7.0 offers "create invoice" on an order until every line is fully
 * invoiced, so an order can be billed over several invoices. The panel hid
 * Convert to invoice as soon as one invoice existed.
 *
 * The figures are core's InvoiceOrderLineRemainderTest: 2.5 x 333.33 is
 * 833.33 (tax 83.33), and invoiced as 1.25 + 1.25 the second invoice bills
 * exactly what is left, so the two add up to the order line.
 */
beforeEach(function () {
    actingAsCrmAdmin();

    $gst = TaxRate::create(['external_id' => (string) Str::uuid(), 'name' => 'GST', 'rate' => 10, 'default' => false]);
    $this->product = Product::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Panel',
        'active' => true,
        'tax_rate_id' => $gst->id,
    ]);

    $this->order = Order::create(['external_id' => (string) Str::uuid(), 'currency' => 'USD']);
    $this->orderProduct = OrderProduct::create([
        'external_id' => (string) Str::uuid(),
        'order_id' => $this->order->id,
        'product_id' => $this->product->id,
        'quantity' => 2.5,
        'price' => 333.33,
        'amount' => 833.33,
        'tax_rate' => 10,
        'tax_amount' => 8333,
        'currency' => 'USD',
    ]);
});

function followUpConvertAction(): Action
{
    $page = (new ReflectionClass(ViewOrder::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(ViewOrder::class, 'getHeaderActions');
    $method->setAccessible(true);

    foreach ($method->invoke($page) as $action) {
        if ($action->getName() === 'convertToInvoice') {
            return $action;
        }
    }

    throw new RuntimeException('ViewOrder has no convertToInvoice header action.');
}

function invoiceOrderQuantity($test, float $quantity): Invoice
{
    return app(InvoiceService::class)->create(FormPayload::wrap([
        'order_id' => $test->order->id,
        'currency' => 'USD',
        'products' => [[
            'order_product_id' => $test->orderProduct->id,
            'id' => $test->product->id,
            'quantity' => $quantity,
            'unit_price' => 333.33,
            'amount' => 0,
            'comments' => null,
        ]],
    ]));
}

/**
 * @return array{0: int, 1: int}
 */
function followUpLineCents(Invoice $invoice): array
{
    $line = $invoice->invoiceLines()->firstOrFail();

    return [(int) $line->getRawOriginal('amount'), (int) $line->getRawOriginal('tax_amount')];
}

it('offers Convert to invoice on an order with nothing invoiced', function () {
    expect(followUpConvertAction()->record($this->order->fresh())->isVisible())->toBeTrue();
});

it('still offers Convert to invoice on a part-invoiced order, prefilled with the remainder', function () {
    $first = invoiceOrderQuantity($this, 1.25);
    expect(followUpLineCents($first))->toBe([41666, 4167]);

    $order = $this->order->fresh();

    expect(followUpConvertAction()->record($order)->isVisible())->toBeTrue();

    $products = OrderDrawdownPrefill::invoiceProducts($order);

    expect($products)->toHaveCount(1)
        ->and((float) $products[0]['quantity'])->toBe(1.25)
        ->and($products[0]['order_product_id'])->toBe($this->orderProduct->id)
        // The last of the quantity bills what is left of the order line.
        ->and((float) $products[0]['amount'])->toBe(416.67)
        ->and((float) $products[0]['tax_amount'])->toBe(41.66);
});

it('bills exactly the rest of the order line on the follow-up invoice and then hides the action', function () {
    $first = invoiceOrderQuantity($this, 1.25);

    followUpConvertAction()->call([
        'record' => $this->order->fresh(),
        'invoiceService' => app(InvoiceService::class),
    ]);

    $second = Invoice::where('order_id', $this->order->id)->whereKeyNot($first->id)->firstOrFail();

    expect(followUpLineCents($second))->toBe([41667, 4166])
        ->and(followUpLineCents($first)[0] + followUpLineCents($second)[0])->toBe(83333)
        ->and(followUpLineCents($first)[1] + followUpLineCents($second)[1])->toBe(8333)
        ->and((int) $second->fresh()->getRawOriginal('total'))->toBe(45833);

    expect(followUpConvertAction()->record($this->order->fresh())->isVisible())->toBeFalse();
});

it('hides Convert to invoice on a fully invoiced order and refuses an empty invoice', function () {
    invoiceOrderQuantity($this, 2.5);

    $order = $this->order->fresh();

    expect(followUpConvertAction()->record($order)->isVisible())->toBeFalse();

    // Called anyway (a page left open while the last invoice was made).
    followUpConvertAction()->call([
        'record' => $order,
        'invoiceService' => app(InvoiceService::class),
    ]);

    expect(Invoice::where('order_id', $this->order->id)->count())->toBe(1);
});
