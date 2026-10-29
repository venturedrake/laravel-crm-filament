<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\OrderProduct;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\PurchaseOrder;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\EditInvoice;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\EditOrder;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\EditQuote;
use VentureDrake\LaravelCrmFilament\Support\OrderDrawdownPrefill;

use function Pest\Livewire\livewire;

/**
 * The line items repeater is reorderable, and core's services save each line's
 * `order` from its position. The edit pages loaded lines in id order, so a
 * reorder was lost the next time the document was opened. Core 2.6.0 restored
 * line ordering on its own forms; the panel loads, and converts, lines in
 * `order` too.
 */
beforeEach(function () {
    actingAsCrmAdmin();

    Setting::create(['external_id' => (string) Str::uuid(), 'name' => 'currency', 'value' => 'USD']);

    $this->first = Product::create(['external_id' => (string) Str::uuid(), 'name' => 'First', 'active' => true]);
    $this->second = Product::create(['external_id' => (string) Str::uuid(), 'name' => 'Second', 'active' => true]);
});

/**
 * Two lines created in id order, then reordered so the second comes first.
 */
function reorderedLines($relation, array $attributes, Product $first, Product $second): void
{
    $relation->create(array_merge($attributes, ['product_id' => $first->id, 'order' => 2]));
    $relation->create(array_merge($attributes, ['product_id' => $second->id, 'order' => 1]));
}

function loadedProductIds($component): array
{
    // Select state comes back as strings.
    return array_map('intval', array_column(array_values($component->get('data.products')), 'id'));
}

$lineAttributes = ['quantity' => 1, 'price' => 1000, 'amount' => 1000, 'currency' => 'USD'];

it('loads quote lines in their saved order', function () use ($lineAttributes) {
    $quote = Quote::create(['external_id' => (string) Str::uuid(), 'title' => 'Reordered', 'currency' => 'USD']);
    reorderedLines($quote->quoteProducts(), $lineAttributes, $this->first, $this->second);

    expect(loadedProductIds(livewire(EditQuote::class, ['record' => $quote->external_id])))
        ->toBe([$this->second->id, $this->first->id]);
});

it('loads order lines in their saved order', function () use ($lineAttributes) {
    $order = Order::create(['external_id' => (string) Str::uuid(), 'currency' => 'USD']);
    reorderedLines($order->orderProducts(), $lineAttributes, $this->first, $this->second);

    expect(loadedProductIds(livewire(EditOrder::class, ['record' => $order->external_id])))
        ->toBe([$this->second->id, $this->first->id]);
});

it('loads invoice lines in their saved order', function () use ($lineAttributes) {
    $invoice = Invoice::create(['external_id' => (string) Str::uuid(), 'currency' => 'USD']);
    reorderedLines($invoice->invoiceLines(), $lineAttributes, $this->first, $this->second);

    expect(loadedProductIds(livewire(EditInvoice::class, ['record' => $invoice->external_id])))
        ->toBe([$this->second->id, $this->first->id]);
});

it('loads purchase order lines in their saved order', function () use ($lineAttributes) {
    $purchaseOrder = PurchaseOrder::create(['external_id' => (string) Str::uuid(), 'currency' => 'USD']);
    reorderedLines($purchaseOrder->purchaseOrderLines(), $lineAttributes, $this->first, $this->second);

    expect(loadedProductIds(livewire(EditPurchaseOrder::class, ['record' => $purchaseOrder->external_id])))
        ->toBe([$this->second->id, $this->first->id]);
});

it('prefills an invoice from an order in the order\'s line order', function () use ($lineAttributes) {
    $order = Order::create(['external_id' => (string) Str::uuid(), 'currency' => 'USD']);
    reorderedLines($order->orderProducts(), $lineAttributes, $this->first, $this->second);

    $orderProductIds = $order->orderProducts()->orderBy('order')->pluck('id')->all();

    expect(array_column(OrderDrawdownPrefill::invoiceProducts($order->fresh()), 'order_product_id'))
        ->toBe($orderProductIds);
});

it('saves a reordered order in its new order', function () use ($lineAttributes) {
    $order = Order::create(['external_id' => (string) Str::uuid(), 'currency' => 'USD']);
    reorderedLines($order->orderProducts(), $lineAttributes, $this->first, $this->second);

    $component = livewire(EditOrder::class, ['record' => $order->external_id]);
    $component->set('data.products', array_reverse($component->get('data.products'), true))
        ->call('save')
        ->assertHasNoFormErrors();

    expect(OrderProduct::query()->where('order_id', $order->id)->orderBy('order')->pluck('product_id')->all())
        ->toBe([$this->first->id, $this->second->id]);
});
