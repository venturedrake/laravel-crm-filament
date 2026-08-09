<?php

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Delivery;
use VentureDrake\LaravelCrm\Models\DeliveryProduct;
use VentureDrake\LaravelCrm\Models\Order;
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

/**
 * Drop a named route from the router, so a route core registers only while
 * laravel-crm.user_interface is on can be tested as absent.
 */
function forgetPortalRoute(string $name): void
{
    $remaining = new RouteCollection;

    foreach (Route::getRoutes() as $route) {
        if ($route->getName() === $name) {
            continue;
        }

        $remaining->add($route);
    }

    app('router')->setRoutes($remaining);
}

it('no longer wires the order portal action into ViewOrder', function () {
    // The Order show-page header was rebuilt around Back / Delivery / Purchase
    // Order / Download / Edit / Delete, and the portal preview went with it —
    // core still registers no `laravel-crm.portal.orders.show` route, so the
    // action could only ever have rendered hidden. The trait ships for hosts
    // that want to add it back.
    expect(viewPageUsesPortalTrait(ViewOrder::class, HasOrderPortalAction::class))->toBeFalse();
    expect(Route::has('laravel-crm.portal.orders.show'))->toBeFalse();
});

it('uses the delivery portal action trait on ViewDelivery', function () {
    expect(viewPageUsesPortalTrait(ViewDelivery::class, HasDeliveryPortalAction::class))->toBeTrue();
});

it('uses the purchase order portal action trait on ViewPurchaseOrder', function () {
    expect(viewPageUsesPortalTrait(ViewPurchaseOrder::class, HasPurchaseOrderPortalAction::class))->toBeTrue();
});

dataset('portalActionTraits', [
    'order' => [HasOrderPortalAction::class, "PortalUrl::for('laravel-crm.portal.orders.show'"],
    'delivery' => [HasDeliveryPortalAction::class, "PortalUrl::for('laravel-crm.portal.deliveries.show'"],
    // The purchase-order trait names the route through the shared constant,
    // so the send action and the preview action cannot drift apart.
    'purchaseOrder' => [HasPurchaseOrderPortalAction::class, 'PortalUrl::for(PurchaseOrderPortalLink::ROUTE'],
]);

it('declares the preview_portal label, primary color, openUrlInNewTab, and the named portal route', function (string $trait, string $portalUrlCall) {
    $source = file_get_contents((new ReflectionClass($trait))->getFileName());

    expect($source)->toContain('actions.preview_portal');
    expect($source)->toContain("->color('primary')");
    expect($source)->toContain('openUrlInNewTab');
    expect($source)->toContain($portalUrlCall);
})->with('portalActionTraits');

it('gates each portal action behind a visible() callback', function (string $trait) {
    $source = file_get_contents((new ReflectionClass($trait))->getFileName());
    expect($source)->toContain('->visible(');
})->with('portalActionTraits');

dataset('viewPages', [
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

it('hides the PurchaseOrder portal action when base registers no portal route', function () {
    // core 2.4.0 ships `laravel-crm.portal.purchase-orders.show` (the upstream
    // fix PortalUrl asked for), so the route has to be removed to exercise the
    // guard. It still matters: the route only loads while
    // laravel-crm.user_interface is on, which the plugin's own installer turns
    // off.
    forgetPortalRoute('laravel-crm.portal.purchase-orders.show');

    expect(Route::has('laravel-crm.portal.purchase-orders.show'))->toBeFalse();

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

    expect($portal->isVisible())->toBeFalse();
});

it('shows the PurchaseOrder portal action once a line is attached', function () {
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
