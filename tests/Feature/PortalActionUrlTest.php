<?php

use Filament\Actions\Action;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Delivery;
use VentureDrake\LaravelCrm\Models\DeliveryProduct;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\OrderProduct;
use VentureDrake\LaravelCrm\Models\PurchaseOrder;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrm\Support\PortalLink;
use VentureDrake\LaravelCrmFilament\Resources\Deliveries\DeliveryResource;
use VentureDrake\LaravelCrmFilament\Resources\Deliveries\Pages\Concerns\HasDeliveryPortalAction;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\InvoiceResource;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\Concerns\HasInvoicePortalAction;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\Concerns\HasOrderPortalAction;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\Concerns\HasQuotePortalAction;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\QuoteResource;
use VentureDrake\LaravelCrmFilament\Support\PortalUrl;
use VentureDrake\LaravelCrmFilament\Support\PurchaseOrderPortalLink;

/**
 * Portal preview links resolve through the base package's named routes.
 * laravel-crm registers no `laravel-crm.portal.orders.show` or
 * `laravel-crm.portal.deliveries.show` route, so those actions must hide
 * rather than render a URL that is guaranteed to 404.
 */
function portalActionFromTrait(string $trait, string $method): Action
{
    $host = new class
    {
        use HasDeliveryPortalAction;
        use HasInvoicePortalAction;
        use HasOrderPortalAction;
        use HasQuotePortalAction;

        public function build(string $method): Action
        {
            return $this->{$method}();
        }
    };

    expect(class_uses_recursive($host))->toContain($trait);

    return $host->build($method);
}

it('returns null from PortalUrl::for when the route is absent', function () {
    $quote = new Quote(['external_id' => (string) Str::uuid()]);

    expect(PortalUrl::exists('laravel-crm.portal.nope.show'))->toBeFalse()
        ->and(PortalUrl::for('laravel-crm.portal.nope.show', $quote))->toBeNull();
});

it('resolves PortalUrl::for through the named route when it is present', function () {
    Route::get('p/widgets/{external_id}', fn () => '')->name('laravel-crm.portal.widgets.show');
    Route::getRoutes()->refreshNameLookups();

    $quote = new Quote(['external_id' => (string) Str::uuid()]);

    expect(PortalUrl::exists('laravel-crm.portal.widgets.show'))->toBeTrue()
        ->and(PortalUrl::for('laravel-crm.portal.widgets.show', $quote))
        ->toBe(route('laravel-crm.portal.widgets.show', $quote->external_id));
});

it('hides the Order portal action while the base package ships no order portal route', function () {
    expect(PortalUrl::exists('laravel-crm.portal.orders.show'))->toBeFalse();

    $order = Order::create(['external_id' => (string) Str::uuid()]);

    OrderProduct::create([
        'external_id' => (string) Str::uuid(),
        'order_id' => $order->id,
        'quantity' => 1,
        'unit_price' => 100,
        'amount' => 100,
    ]);

    $action = portalActionFromTrait(HasOrderPortalAction::class, 'orderPortalAction')->record($order);

    expect($action->isVisible())->toBeFalse();
});

it('hides the Delivery portal action while the base package ships no delivery portal route', function () {
    expect(PortalUrl::exists('laravel-crm.portal.deliveries.show'))->toBeFalse();

    $delivery = Delivery::create(['external_id' => (string) Str::uuid()]);

    DeliveryProduct::create([
        'external_id' => (string) Str::uuid(),
        'delivery_id' => $delivery->id,
        'quantity' => 1,
    ]);

    $action = portalActionFromTrait(HasDeliveryPortalAction::class, 'deliveryPortalAction')->record($delivery);

    expect($action->isVisible())->toBeFalse();
});

it('hides the Delivery resource portal action factory when the route is absent', function () {
    $delivery = Delivery::create(['external_id' => (string) Str::uuid()]);

    $action = DeliveryResource::deliveryPortalActionFactory()->record($delivery);

    expect($action->isVisible())->toBeFalse();
});

it('shows the Order and Delivery portal actions once the routes exist', function () {
    Route::get('p/orders/{external_id}', fn () => '')->name('laravel-crm.portal.orders.show');
    Route::get('p/deliveries/{external_id}', fn () => '')->name('laravel-crm.portal.deliveries.show');
    Route::getRoutes()->refreshNameLookups();

    $order = Order::create(['external_id' => (string) Str::uuid()]);

    OrderProduct::create([
        'external_id' => (string) Str::uuid(),
        'order_id' => $order->id,
        'quantity' => 1,
        'unit_price' => 100,
        'amount' => 100,
    ]);

    $delivery = Delivery::create(['external_id' => (string) Str::uuid()]);

    DeliveryProduct::create([
        'external_id' => (string) Str::uuid(),
        'delivery_id' => $delivery->id,
        'quantity' => 1,
    ]);

    $orderAction = portalActionFromTrait(HasOrderPortalAction::class, 'orderPortalAction')->record($order);
    $deliveryAction = portalActionFromTrait(HasDeliveryPortalAction::class, 'deliveryPortalAction')->record($delivery);

    expect($orderAction->isVisible())->toBeTrue()
        ->and($orderAction->getUrl())->toBe(route('laravel-crm.portal.orders.show', $order->external_id))
        ->and($deliveryAction->isVisible())->toBeTrue()
        ->and($deliveryAction->getUrl())->toBe(route('laravel-crm.portal.deliveries.show', $delivery->external_id));
});

/**
 * Every portal controller in core opens with
 * `abort_unless($request->hasValidSignature(), 401)`, so a preview link that
 * carries no signature is a 401 rather than a document. Asserting the URL
 * merely *starts with* the route would pass for the unsigned form too, so
 * these check the signature the way core's middleware does.
 */
function assertValidPortalSignature(?string $url): void
{
    expect($url)->toBeString();

    expect(URL::hasValidSignature(Request::create((string) $url)))->toBeTrue();
}

it('signs the Quote portal action link so the portal does not 401 it', function () {
    expect(PortalUrl::exists('laravel-crm.portal.quotes.show'))->toBeTrue();

    $quote = new Quote(['external_id' => (string) Str::uuid()]);

    $unsigned = route('laravel-crm.portal.quotes.show', $quote->external_id);

    $factoryAction = QuoteResource::portalActionFactory()->record($quote);
    $traitAction = portalActionFromTrait(HasQuotePortalAction::class, 'quotePortalAction')->record($quote);

    foreach ([$factoryAction, $traitAction] as $action) {
        expect($action->isVisible())->toBeTrue()
            ->and($action->getUrl())->toStartWith($unsigned . '?')
            ->and($action->getUrl())->not->toBe($unsigned);

        assertValidPortalSignature($action->getUrl());
    }
});

it('signs the Invoice portal action link so the portal does not 401 it', function () {
    expect(PortalUrl::exists('laravel-crm.portal.invoices.show'))->toBeTrue();

    $invoice = new Invoice(['external_id' => (string) Str::uuid()]);

    $unsigned = route('laravel-crm.portal.invoices.show', $invoice->external_id);

    $factoryAction = InvoiceResource::invoicePortalActionFactory()->record($invoice);
    $traitAction = portalActionFromTrait(HasInvoicePortalAction::class, 'invoicePortalAction')->record($invoice);

    foreach ([$factoryAction, $traitAction] as $action) {
        expect($action->isVisible())->toBeTrue()
            ->and($action->getUrl())->toStartWith($unsigned . '?')
            ->and($action->getUrl())->not->toBe($unsigned);

        assertValidPortalSignature($action->getUrl());
    }
});

/**
 * The expiry only means anything if every surface agrees on it: a link copied
 * out of the panel and a link core mailed are the same link, so they must not
 * outlive each other. That is why the number is read from core's PortalLink
 * rather than restated here.
 */
it('expires every portal link core.PortalLink::DAYS out, on all five surfaces', function () {
    $this->travelTo(Carbon::parse('2026-09-07 12:00:00'));

    $quote = new Quote(['external_id' => (string) Str::uuid()]);
    $invoice = new Invoice(['external_id' => (string) Str::uuid()]);
    $purchaseOrder = new PurchaseOrder(['external_id' => (string) Str::uuid()]);

    Route::get('p/purchase-orders/{external_id}', fn () => '')
        ->name(PurchaseOrderPortalLink::ROUTE);
    Route::getRoutes()->refreshNameLookups();

    $urls = [
        'quote preview' => QuoteResource::portalActionFactory()->record($quote)->getUrl(),
        'invoice preview' => InvoiceResource::invoicePortalActionFactory()->record($invoice)->getUrl(),
        'purchase order preview' => PurchaseOrderPortalLink::signedFor($purchaseOrder),
        'quote send' => PortalUrl::signedFor('laravel-crm.portal.quotes.show', $quote),
        'invoice send' => PortalUrl::signedFor('laravel-crm.portal.invoices.show', $invoice),
    ];

    $expected = now()->addDays(PortalLink::DAYS)->getTimestamp();

    foreach ($urls as $surface => $url) {
        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

        expect((int) ($query['expires'] ?? 0))->toBe($expected, $surface);
    }

    expect(PurchaseOrderPortalLink::EXPIRY_DAYS)->toBe(PortalLink::DAYS);
});

it('no longer hard-codes the portal prefix anywhere under src/Resources', function () {
    $offenders = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src/Resources', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        if (preg_match("/url\('p\//", (string) file_get_contents($file->getPathname())) === 1) {
            $offenders[] = $file->getPathname();
        }
    }

    expect($offenders)->toBe([]);
});
