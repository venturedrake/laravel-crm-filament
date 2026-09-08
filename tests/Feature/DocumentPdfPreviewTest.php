<?php

use Filament\Http\Middleware\Authenticate;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Delivery;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\PurchaseOrder;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrmFilament\Resources\Deliveries\DeliveryResource;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\InvoiceResource;
use VentureDrake\LaravelCrmFilament\Resources\Orders\OrderResource;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\PurchaseOrderResource;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\QuoteResource;
use VentureDrake\LaravelCrmFilament\Support\DocumentPreview;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

/**
 * The in-panel PDF preview slide-over.
 *
 * Core 2.4.1 put a preview drawer beside every download button so a document
 * can be checked before it is sent; the panel had no equivalent, and the eye
 * icon on those rows is Filament's ViewAction, which navigates to the record
 * page rather than showing the PDF.
 *
 * What actually has to hold for the drawer to work — and what silently does not
 * when it renders empty — is that the iframe's `src` resolves to a route that
 * returns PDF bytes *inline*. `attachment` is a download prompt, not a preview.
 */
function previewRecordFor(string $type)
{
    $order = fn () => Order::create([
        'external_id' => (string) Str::uuid(),
        'order_id' => 'ORD-1',
        'currency' => 'USD',
        'subtotal' => 10000,
        'tax' => 1000,
        'total' => 11000,
    ]);

    return match ($type) {
        'quote' => Quote::create([
            'external_id' => (string) Str::uuid(),
            'quote_id' => 'QUO-1',
            'title' => 'Sample quote',
            'currency' => 'USD',
            'issue_at' => now(),
            'expire_at' => now()->addDays(30),
        ]),
        'invoice' => Invoice::create([
            'external_id' => (string) Str::uuid(),
            'invoice_id' => 'INV-1',
            'currency' => 'USD',
            'issue_date' => now(),
            'due_date' => now()->addDays(30),
        ]),
        'order' => $order(),
        'delivery' => Delivery::create([
            'external_id' => (string) Str::uuid(),
            'delivery_id' => 'DEL-1',
            'order_id' => $order()->id,
            'delivery_expected' => now(),
        ]),
        'purchaseorder' => PurchaseOrder::create([
            'external_id' => (string) Str::uuid(),
            'purchase_order_id' => 'PO-1',
            'currency' => 'USD',
            'issue_date' => now(),
            'delivery_date' => now()->addDays(14),
        ]),
    };
}

function actingAsPreviewUser(): User
{
    RoleSeeder::seed();

    $user = User::create([
        'name' => 'Preview User',
        'email' => 'preview-' . Str::random(8) . '@example.com',
        'password' => bcrypt('secret'),
    ]);

    $user->assignRole('Admin');

    test()->actingAs($user->fresh());

    return $user->fresh();
}

it('registers the preview route inside the panel auth middleware', function () {
    expect(DocumentPreview::available())->toBeTrue()
        ->and(DocumentPreview::routeName())->toBe('filament.admin.laravel-crm-filament.document-preview');

    $route = app('router')->getRoutes()->getByName(DocumentPreview::routeName());

    expect($route)->not->toBeNull()
        // The authenticated group is what separates this from the invitation
        // route, which is deliberately public.
        ->and($route->gatherMiddleware())->toContain(Authenticate::class);
});

it('serves PDF bytes inline, not as an attachment, for every doc type', function (string $type) {
    actingAsPreviewUser();

    $record = previewRecordFor($type);

    $response = $this->get(DocumentPreview::urlFor($record));

    $response->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('application/pdf')
        // The whole bug this guards: an `attachment` disposition renders a
        // download prompt inside the slide-over instead of the document.
        ->and($response->headers->get('Content-Disposition'))->toStartWith('inline');

    expect(substr($response->streamedContent(), 0, 4))->toBe('%PDF');
})->with(['quote', 'invoice', 'order', 'delivery', 'purchaseorder']);

it('404s an unknown doc type rather than resolving a class name off the request', function () {
    actingAsPreviewUser();

    $invoice = previewRecordFor('invoice');

    $this->get(route(DocumentPreview::routeName(), [
        'type' => 'App\\Models\\User',
        'record' => $invoice->external_id,
    ]))->assertNotFound();

    expect(DocumentPreview::modelFor('App\\Models\\User'))->toBeNull()
        ->and(DocumentPreview::modelFor('not-a-doc-type'))->toBeNull();
});

it('rejects a guest rather than rendering a customer document', function () {
    $invoice = previewRecordFor('invoice');

    $url = route(DocumentPreview::routeName(), [
        'type' => 'invoice',
        'record' => $invoice->external_id,
    ]);

    // Asserted as the exception rather than as a 302: the testbench app has no
    // `login` route for Laravel's handler to redirect to, so the status here
    // says more about the harness than about the guard. What matters is that
    // the auth middleware rejected the request before the controller ran.
    $this->withoutExceptionHandling();

    expect(fn () => $this->get($url))
        ->toThrow(AuthenticationException::class);
});

it('403s a panel user who may not view the record', function () {
    RoleSeeder::seed();

    $stranger = User::create([
        'name' => 'Preview Stranger',
        'email' => 'preview-stranger-' . Str::random(8) . '@example.com',
        'password' => bcrypt('secret'),
    ]);

    $this->actingAs($stranger->fresh());

    $invoice = previewRecordFor('invoice');

    // Being inside the panel's auth middleware establishes *a* panel user, not
    // that this one may read this document — so the controller re-checks.
    $this->get(route(DocumentPreview::routeName(), [
        'type' => 'invoice',
        'record' => $invoice->external_id,
    ]))->assertForbidden();
});

it('404s a record that does not exist', function () {
    actingAsPreviewUser();

    $this->get(route(DocumentPreview::routeName(), [
        'type' => 'invoice',
        'record' => (string) Str::uuid(),
    ]))->assertNotFound();
});

dataset('previewSurfaces', [
    'quote' => [QuoteResource::class, 'quote'],
    'order' => [OrderResource::class, 'order'],
    'invoice' => [InvoiceResource::class, 'invoice'],
    'delivery' => [DeliveryResource::class, 'delivery'],
    'purchaseOrder' => [PurchaseOrderResource::class, 'purchaseorder'],
]);

it('opens the preview in a slide-over, not a centred modal or a new tab', function (string $resource, string $type) {
    actingAsPreviewUser();

    $action = $resource::pdfPreviewAction($type)->record(previewRecordFor($type));

    expect($action->getName())->toBe('previewPdf')
        ->and($action->isVisible())->toBeTrue()
        ->and($action->isModalSlideOver())->toBeTrue()
        // A URL would navigate away, which is the behaviour being replaced.
        ->and($action->getUrl())->toBeNull();
})->with('previewSurfaces');

it('renders an iframe pointed at the inline PDF route', function (string $resource, string $type) {
    actingAsPreviewUser();

    $record = previewRecordFor($type);
    $action = $resource::pdfPreviewAction($type)->record($record);

    $html = (string) $action->getModalContent()?->render();

    expect($html)->toContain('<iframe')
        ->toContain(DocumentPreview::urlFor($record));
})->with('previewSurfaces');

it('uses the magnifying-glass icon so it does not collide with the ViewAction eye', function (string $resource, string $type) {
    $action = $resource::pdfPreviewAction($type);

    expect($action->getIcon())->toBe('heroicon-o-document-magnifying-glass');
})->with('previewSurfaces');

it('hides the preview when the panel does not carry the plugin route', function () {
    actingAsPreviewUser();

    $invoice = previewRecordFor('invoice');

    // A host can build a panel without this plugin's routes. Drop the route
    // rather than the panel: `available()` deliberately falls back to the
    // default panel, so nulling the current one proves nothing.
    $remaining = new RouteCollection;

    foreach (Route::getRoutes() as $route) {
        if ($route->getName() === DocumentPreview::routeName()) {
            continue;
        }

        $remaining->add($route);
    }

    Route::setRoutes($remaining);

    expect(DocumentPreview::available())->toBeFalse()
        ->and(DocumentPreview::urlFor($invoice))->toBeNull()
        ->and(InvoiceResource::pdfPreviewAction('invoice')->record($invoice)->isVisible())->toBeFalse();
});

it('has no preview for a record type that is not a document', function () {
    actingAsPreviewUser();

    expect(DocumentPreview::typeFor(new User))->toBeNull()
        ->and(DocumentPreview::urlFor(new User))->toBeNull();
});

it('maps every doc type in both directions', function () {
    expect(array_keys(DocumentPreview::TYPES))
        ->toBe(['quote', 'order', 'invoice', 'delivery', 'purchaseorder']);

    foreach (['quote', 'order', 'invoice', 'delivery', 'purchaseorder'] as $type) {
        expect(DocumentPreview::typeFor(previewRecordFor($type)))->toBe($type);
    }
});
