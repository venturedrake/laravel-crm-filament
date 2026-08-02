<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Delivery;
use VentureDrake\LaravelCrm\Models\DeliveryProduct;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\OrderProduct;
use VentureDrake\LaravelCrm\Models\PurchaseOrder;
use VentureDrake\LaravelCrm\Models\PurchaseOrderLine;
use VentureDrake\LaravelCrmFilament\Resources\Deliveries\Pages\Concerns\HasDeliveryPortalAction;
use VentureDrake\LaravelCrmFilament\Resources\Deliveries\Pages\ViewDelivery;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\Concerns\HasOrderPortalAction;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\ViewOrder;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\Pages\Concerns\HasPurchaseOrderPortalAction;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;

/**
 * Structural + runtime assertions for the v0.11-style Preview-portal header action
 * extended in US-019 to Order, Delivery, and PurchaseOrder view pages.
 */
function viewPageUsesPortalTrait(string $page, string $trait): bool
{
    return in_array($trait, class_uses_recursive($page), true);
}

it('uses the order portal action trait on ViewOrder', function () {
    expect(viewPageUsesPortalTrait(ViewOrder::class, HasOrderPortalAction::class))->toBeTrue();
});

it('uses the delivery portal action trait on ViewDelivery', function () {
    expect(viewPageUsesPortalTrait(ViewDelivery::class, HasDeliveryPortalAction::class))->toBeTrue();
});

it('uses the purchase order portal action trait on ViewPurchaseOrder', function () {
    expect(viewPageUsesPortalTrait(ViewPurchaseOrder::class, HasPurchaseOrderPortalAction::class))->toBeTrue();
});

dataset('portalActionTraits', [
    'order' => [HasOrderPortalAction::class, 'laravel-crm.portal.orders.show'],
    'delivery' => [HasDeliveryPortalAction::class, 'laravel-crm.portal.deliveries.show'],
    'purchaseOrder' => [HasPurchaseOrderPortalAction::class, 'laravel-crm.portal.purchase-orders.show'],
]);

it('declares the preview_portal label, primary color, openUrlInNewTab, and the named portal route', function (string $trait, string $routeName) {
    $source = file_get_contents((new ReflectionClass($trait))->getFileName());

    expect($source)->toContain('actions.preview_portal');
    expect($source)->toContain("->color('primary')");
    expect($source)->toContain('openUrlInNewTab');
    expect($source)->toContain("PortalUrl::for('" . $routeName . "'");
})->with('portalActionTraits');

it('gates each portal action behind a visible() callback', function (string $trait) {
    $source = file_get_contents((new ReflectionClass($trait))->getFileName());
    expect($source)->toContain('->visible(');
})->with('portalActionTraits');

dataset('viewPages', [
    'order' => [ViewOrder::class, 'previewPortal'],
    'delivery' => [ViewDelivery::class, 'previewPortal'],
    'purchaseOrder' => [ViewPurchaseOrder::class, 'previewPortal'],
]);

it('registers the previewPortal action in getHeaderActions', function (string $page, string $expectedAction) {
    $instance = (new ReflectionClass($page))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod($page, 'getHeaderActions');
    $method->setAccessible(true);
    $actions = $method->invoke($instance);

    $names = array_map(fn ($action) => $action->getName(), $actions);

    expect($names)->toContain($expectedAction);
})->with('viewPages');

it('hides the Order portal action when there are no order products', function () {
    $order = Order::create([
        'external_id' => (string) Str::uuid(),
    ]);

    $page = (new ReflectionClass(ViewOrder::class))->newInstanceWithoutConstructor();
    $page->record = $order;

    $method = new ReflectionMethod(ViewOrder::class, 'getHeaderActions');
    $method->setAccessible(true);
    $actions = $method->invoke($page);

    $portal = collect($actions)->first(fn ($a) => $a->getName() === 'previewPortal');
    $portal->record($order);

    expect($portal->isVisible())->toBeFalse();
});

it('shows the Order portal action once an order product is attached', function () {
    Route::get('p/orders/{external_id}', fn () => '')->name('laravel-crm.portal.orders.show');
    Route::getRoutes()->refreshNameLookups();

    $order = Order::create([
        'external_id' => (string) Str::uuid(),
    ]);

    OrderProduct::create([
        'external_id' => (string) Str::uuid(),
        'order_id' => $order->id,
        'quantity' => 1,
        'unit_price' => 100,
        'amount' => 100,
    ]);

    $page = (new ReflectionClass(ViewOrder::class))->newInstanceWithoutConstructor();
    $page->record = $order;

    $method = new ReflectionMethod(ViewOrder::class, 'getHeaderActions');
    $method->setAccessible(true);
    $actions = $method->invoke($page);

    $portal = collect($actions)->first(fn ($a) => $a->getName() === 'previewPortal');
    $portal->record($order);

    expect($portal->isVisible())->toBeTrue();
    expect($portal->getUrl())->toContain('p/orders/' . $order->external_id);
});

it('hides the Delivery portal action when there are no delivery products', function () {
    $delivery = Delivery::create([
        'external_id' => (string) Str::uuid(),
    ]);

    $page = (new ReflectionClass(ViewDelivery::class))->newInstanceWithoutConstructor();
    $page->record = $delivery;

    $method = new ReflectionMethod(ViewDelivery::class, 'getHeaderActions');
    $method->setAccessible(true);
    $actions = $method->invoke($page);

    $portal = collect($actions)->first(fn ($a) => $a->getName() === 'previewPortal');
    $portal->record($delivery);

    expect($portal->isVisible())->toBeFalse();
});

it('shows the Delivery portal action once a delivery product is attached', function () {
    Route::get('p/deliveries/{external_id}', fn () => '')->name('laravel-crm.portal.deliveries.show');
    Route::getRoutes()->refreshNameLookups();

    $delivery = Delivery::create([
        'external_id' => (string) Str::uuid(),
    ]);

    DeliveryProduct::create([
        'external_id' => (string) Str::uuid(),
        'delivery_id' => $delivery->id,
        'quantity' => 1,
    ]);

    $page = (new ReflectionClass(ViewDelivery::class))->newInstanceWithoutConstructor();
    $page->record = $delivery;

    $method = new ReflectionMethod(ViewDelivery::class, 'getHeaderActions');
    $method->setAccessible(true);
    $actions = $method->invoke($page);

    $portal = collect($actions)->first(fn ($a) => $a->getName() === 'previewPortal');
    $portal->record($delivery);

    expect($portal->isVisible())->toBeTrue();
    expect($portal->getUrl())->toContain('p/deliveries/' . $delivery->external_id);
});

it('hides the PurchaseOrder portal action when there are no purchase order lines', function () {
    $po = PurchaseOrder::create([
        'external_id' => (string) Str::uuid(),
    ]);

    $page = (new ReflectionClass(ViewPurchaseOrder::class))->newInstanceWithoutConstructor();
    $page->record = $po;

    $method = new ReflectionMethod(ViewPurchaseOrder::class, 'getHeaderActions');
    $method->setAccessible(true);
    $actions = $method->invoke($page);

    $portal = collect($actions)->first(fn ($a) => $a->getName() === 'previewPortal');
    $portal->record($po);

    expect($portal->isVisible())->toBeFalse();
});

it('shows the PurchaseOrder portal action once a line is attached', function () {
    Route::get('p/purchase-orders/{external_id}', fn () => '')->name('laravel-crm.portal.purchase-orders.show');
    Route::getRoutes()->refreshNameLookups();

    $po = PurchaseOrder::create([
        'external_id' => (string) Str::uuid(),
    ]);

    PurchaseOrderLine::create([
        'external_id' => (string) Str::uuid(),
        'purchase_order_id' => $po->id,
        'quantity' => 1,
        'unit_price' => 100,
        'amount' => 100,
    ]);

    $page = (new ReflectionClass(ViewPurchaseOrder::class))->newInstanceWithoutConstructor();
    $page->record = $po;

    $method = new ReflectionMethod(ViewPurchaseOrder::class, 'getHeaderActions');
    $method->setAccessible(true);
    $actions = $method->invoke($page);

    $portal = collect($actions)->first(fn ($a) => $a->getName() === 'previewPortal');
    $portal->record($po);

    expect($portal->isVisible())->toBeTrue();
    expect($portal->getUrl())->toContain('p/purchase-orders/' . $po->external_id);
});
