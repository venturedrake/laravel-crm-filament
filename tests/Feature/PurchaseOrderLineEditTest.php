<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\ProductVariation;
use VentureDrake\LaravelCrm\Models\PurchaseOrder;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;

/**
 * Core 2.5.0's PurchaseOrderService::update() reads the `products` rows the
 * panel's form submits (it used to read `purchaseOrderLines`, which nothing
 * sent, so line edits were silently dropped).
 */
beforeEach(function () {
    actingAsCrmAdmin();

    Setting::create(['external_id' => (string) Str::uuid(), 'name' => 'currency', 'value' => 'USD']);

    $this->product = Product::create(['external_id' => (string) Str::uuid(), 'name' => 'Ring', 'active' => true]);
    $this->variation = ProductVariation::create(['product_id' => $this->product->id, 'name' => 'Gold', 'order' => 1, 'active' => true]);

    $this->purchaseOrder = PurchaseOrder::create(['external_id' => (string) Str::uuid(), 'currency' => 'USD']);
    $this->line = $this->purchaseOrder->purchaseOrderLines()->create([
        'product_id' => $this->product->id,
        'quantity' => 1,
        'price' => 10,
        'amount' => 10,
        'currency' => 'USD',
    ]);
});

function savePurchaseOrder(PurchaseOrder $purchaseOrder, array $products): PurchaseOrder
{
    $method = new ReflectionMethod(EditPurchaseOrder::class, 'handleRecordUpdate');

    return $method->invoke(new EditPurchaseOrder, $purchaseOrder, [
        'currency' => 'USD',
        'products' => $products,
    ]);
}

it('saves edits to an existing line, including its variation', function () {
    savePurchaseOrder($this->purchaseOrder, [
        'row1' => [
            'purchase_order_line_id' => $this->line->id,
            'id' => $this->product->id,
            'product_variation_id' => $this->variation->id,
            'quantity' => 4,
            'unit_price' => 12.5,
            'amount' => 50,
            'comments' => 'Rush',
        ],
    ]);

    $line = $this->line->fresh();

    expect((float) $line->quantity)->toBe(4.0)
        ->and($line->price)->toBe(1250)
        ->and($line->comments)->toBe('Rush')
        ->and($line->product_variation_id)->toBe($this->variation->id)
        ->and($this->purchaseOrder->purchaseOrderLines()->count())->toBe(1);
});

it('removes a line that is no longer submitted', function () {
    savePurchaseOrder($this->purchaseOrder, []);

    expect($this->purchaseOrder->purchaseOrderLines()->count())->toBe(0);
});

it('no longer sends the purchaseOrderLines shim', function () {
    $source = file_get_contents((new ReflectionClass(EditPurchaseOrder::class))->getFileName());

    expect($source)->not->toContain("\$data['purchaseOrderLines']");
});
