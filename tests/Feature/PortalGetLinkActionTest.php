<?php

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\PurchaseOrder;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrm\Support\PortalLink;
use VentureDrake\LaravelCrmFilament\Concerns\HasPortalLinkAction;
use VentureDrake\LaravelCrmFilament\Resources\Deliveries\DeliveryResource;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\InvoiceResource;
use VentureDrake\LaravelCrmFilament\Resources\Orders\OrderResource;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\PurchaseOrderResource;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\QuoteResource;
use VentureDrake\LaravelCrmFilament\Support\PortalUrl;
use VentureDrake\LaravelCrmFilament\Support\PurchaseOrderPortalLink;

/**
 * The "Get link" action — core 2.4.1's modal.
 *
 * Before it, the only way to obtain the signed portal URL a customer receives
 * was to send the document. What has to hold is that it hands over the *same*
 * link: a "copy link" that quietly minted a longer-lived or differently-signed
 * URL would be a second credential wearing the first one's name.
 *
 * The modal field's rendered value is deliberately not asserted here.
 * Building an Action's schema outside a mounted Livewire component means
 * hand-feeding Filament a container and a livewire instance, which tests
 * Filament's resolution rather than this plugin's. The guarantee is split into
 * the two halves that can each be checked honestly: the URL the field is fed
 * (below, through the same call the trait makes) and the fact that the trait
 * feeds it that call rather than minting its own (the source guard at the end).
 */
it('feeds the modal a validly signed link, on every doc type that has a portal', function (string $route, callable $make) {
    $url = PortalUrl::signedFor($route, $make());

    expect($url)->toBeString()
        ->and(URL::hasValidSignature(Request::create((string) $url)))->toBeTrue();
})->with([
    'quote' => [
        'laravel-crm.portal.quotes.show',
        fn () => new Quote(['external_id' => (string) Str::uuid()]),
    ],
    'invoice' => [
        'laravel-crm.portal.invoices.show',
        fn () => new Invoice(['external_id' => (string) Str::uuid()]),
    ],
]);

it('hands over exactly the link the mailer embeds, with core PortalLink::DAYS expiry', function () {
    $this->travelTo(Carbon::parse('2026-09-08 09:00:00'));

    $invoice = new Invoice(['external_id' => (string) Str::uuid()]);

    // The Get-link modal and the send path call the same thing, so they cannot
    // hand out links that differ in signature or lifetime.
    $url = PortalUrl::signedFor('laravel-crm.portal.invoices.show', $invoice);

    parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

    expect((int) ($query['expires'] ?? 0))->toBe(now()->addDays(PortalLink::DAYS)->getTimestamp());
});

it('names the action getPortalLink and hides it when core registers no such route', function (string $resource, string $route, callable $make) {
    $record = $make();

    $action = $resource::portalLinkAction($route)->record($record);

    expect($action->getName())->toBe('getPortalLink')
        ->and($action->getIcon())->toBe('heroicon-o-link')
        ->and($action->isVisible())->toBeTrue();

    // `laravel-crm.user_interface=false` unloads portal-routes.php, and that is
    // the configuration this plugin's own installer recommends.
    expect($resource::portalLinkAction('laravel-crm.portal.nope.show')->record($record)->isVisible())
        ->toBeFalse();
})->with([
    'quote' => [
        QuoteResource::class,
        'laravel-crm.portal.quotes.show',
        fn () => new Quote(['external_id' => (string) Str::uuid()]),
    ],
    'invoice' => [
        InvoiceResource::class,
        'laravel-crm.portal.invoices.show',
        fn () => new Invoice(['external_id' => (string) Str::uuid()]),
    ],
]);

it('offers Get link on exactly the three doc types that have a portal page', function () {
    foreach ([QuoteResource::class, InvoiceResource::class, PurchaseOrderResource::class] as $resource) {
        expect(method_exists($resource, 'portalLinkAction'))->toBeTrue($resource);
    }

    // Orders and deliveries have PDF previews but no portal route, so there is
    // nothing to hand over — asserted here so adding one is a deliberate act.
    foreach ([
        OrderResource::class,
        DeliveryResource::class,
    ] as $resource) {
        expect(in_array(HasPortalLinkAction::class, class_uses_recursive($resource), true))
            ->toBeFalse($resource . ' should not offer a portal link');
    }
});

it('routes the purchase-order link through the shared helper rather than a second route name', function () {
    $purchaseOrder = new PurchaseOrder(['external_id' => (string) Str::uuid()]);

    expect(PurchaseOrderPortalLink::ROUTE)->toBe('laravel-crm.portal.purchase-orders.show');

    // Core still registers no purchase-order portal route, so the action hides
    // rather than offering an empty box.
    expect(PurchaseOrderResource::portalLinkAction(PurchaseOrderPortalLink::ROUTE)->record($purchaseOrder)->isVisible())
        ->toBe(PortalUrl::exists(PurchaseOrderPortalLink::ROUTE));
});

/**
 * The half the schema assertion cannot reach: that the field is fed
 * `PortalUrl::signedFor()` rather than `route()` or a fresh
 * `URL::temporarySignedRoute()` with its own expiry.
 */
it('mints the modal link through PortalUrl::signedFor, not its own signed route', function () {
    $source = (string) file_get_contents((new ReflectionClass(HasPortalLinkAction::class))->getFileName());

    expect($source)->toContain('PortalUrl::signedFor($routeName, $record)')
        ->not->toContain('temporarySignedRoute')
        ->not->toContain('addDays(');
});
