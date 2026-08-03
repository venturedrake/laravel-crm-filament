<?php

use Illuminate\Support\Facades\Route;
use VentureDrake\LaravelCrm\Mail\SendPurchaseOrder;
use VentureDrake\LaravelCrm\Models\PurchaseOrder;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\PurchaseOrderResource;
use VentureDrake\LaravelCrmFilament\Support\PurchaseOrderPortalLink;

/**
 * laravel-crm registers portal routes for quotes and invoices only. Base's
 * `SendPurchaseOrder` mailable expands `[Online Purchase Order Link]`
 * unconditionally, so handing it an empty link mails `<a href=""></a>` — an
 * anchor with neither an href nor visible text. These lock in that the
 * placeholder is only ever offered, and only ever expanded, when the route
 * actually resolves.
 */
function purchaseOrderPortalRoute(): void
{
    Route::get('p/purchase-orders/{external_id}', fn () => '')
        ->name(PurchaseOrderPortalLink::ROUTE);
    Route::getRoutes()->refreshNameLookups();
}

it('reports the portal as unavailable on a stock install', function () {
    expect(Route::has(PurchaseOrderPortalLink::ROUTE))->toBeFalse();
    expect(PurchaseOrderPortalLink::available())->toBeFalse();
});

it('omits the placeholder from the default message when there is no portal route', function () {
    $message = PurchaseOrderPortalLink::defaultMessage();

    expect($message)->not->toContain(PurchaseOrderPortalLink::PLACEHOLDER);
    expect($message)->toContain('attached');
});

it('offers the placeholder in the default message once the route exists', function () {
    purchaseOrderPortalRoute();

    expect(PurchaseOrderPortalLink::available())->toBeTrue();
    expect(PurchaseOrderPortalLink::defaultMessage())
        ->toContain(PurchaseOrderPortalLink::PLACEHOLDER);
});

it('returns null rather than an empty string when the route is missing', function () {
    $po = PurchaseOrder::create(['external_id' => (string) Str::uuid()]);

    expect(PurchaseOrderPortalLink::signedFor($po))->toBeNull();
});

it('signs a temporary portal link once the route exists', function () {
    purchaseOrderPortalRoute();

    $po = PurchaseOrder::create(['external_id' => (string) Str::uuid()]);

    expect(PurchaseOrderPortalLink::signedFor($po))
        ->toContain('p/purchase-orders/')
        ->toContain('signature=');
});

it('drops the whole line a hand-typed placeholder sits on', function () {
    $stripped = PurchaseOrderPortalLink::stripPlaceholder(
        "Hi,\n\nPlease find the purchase order here: " . PurchaseOrderPortalLink::PLACEHOLDER . "\n\nThanks.",
    );

    expect($stripped)->not->toContain(PurchaseOrderPortalLink::PLACEHOLDER)
        // The dangling "here:" goes with it, rather than being mailed alone.
        ->not->toContain('purchase order here:')
        ->toContain('Hi,')
        ->toContain('Thanks.');
});

it('leaves a message without the placeholder untouched', function () {
    $message = "Hi,\n\nThe purchase order is attached.\n\nThanks.";

    expect(PurchaseOrderPortalLink::stripPlaceholder($message))->toBe($message);
});

it('never mails an empty anchor for the portal link', function () {
    // What base's mailable does with whatever it is handed.
    $mail = new SendPurchaseOrder([
        'to' => 'supplier@example.com',
        'subject' => 'Purchase Order 1',
        'message' => PurchaseOrderPortalLink::stripPlaceholder(
            'Please find the purchase order here: ' . PurchaseOrderPortalLink::PLACEHOLDER,
        ),
        'cc' => 0,
        'onlinePurchaseOrderLink' => PurchaseOrderPortalLink::signedFor(
            PurchaseOrder::create(['external_id' => (string) Str::uuid()]),
        ) ?? '',
        'pdf' => null,
    ]);

    expect($mail->content ?? '')->not->toContain('<a href=""></a>');
});

it('gates both purchase-order send paths on the shared support class', function (string $file) {
    $source = file_get_contents($file);

    expect($source)->toContain('PurchaseOrderPortalLink::signedFor(')
        ->toContain('PurchaseOrderPortalLink::stripPlaceholder(')
        ->toContain('PurchaseOrderPortalLink::defaultMessage()')
        // The literal placeholder must not be hard-coded as a default any more.
        ->not->toContain('[Online Purchase Order Link]');
})->with([
    'page trait' => [__DIR__ . '/../../src/Resources/PurchaseOrders/Pages/Concerns/HasPurchaseOrderSendAction.php'],
    'resource' => [__DIR__ . '/../../src/Resources/PurchaseOrders/PurchaseOrderResource.php'],
]);

it('hides the resource-level preview action while the route is missing', function () {
    $action = PurchaseOrderResource::purchaseOrderPortalActionFactory();
    $action->record(PurchaseOrder::create(['external_id' => (string) Str::uuid()]));

    expect($action->isVisible())->toBeFalse();
});

it('shows the resource-level preview action with a real URL once the route exists', function () {
    purchaseOrderPortalRoute();

    $po = PurchaseOrder::create(['external_id' => (string) Str::uuid()]);

    $action = PurchaseOrderResource::purchaseOrderPortalActionFactory();
    $action->record($po);

    expect($action->isVisible())->toBeTrue();
    expect($action->getUrl())->toContain('p/purchase-orders/' . $po->external_id);
});
