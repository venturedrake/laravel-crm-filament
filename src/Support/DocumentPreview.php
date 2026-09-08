<?php

namespace VentureDrake\LaravelCrmFilament\Support;

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrm\Models\Delivery;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\PurchaseOrder;
use VentureDrake\LaravelCrm\Models\Quote;

/**
 * The in-panel PDF preview: the URL the slide-over's iframe points at, and the
 * doc-type whitelist behind it.
 *
 * Core 2.4.1 added a preview drawer beside every download button — a document
 * gets checked before it is sent, and doing that through the browser's download
 * tray and the OS file browser is a bad trade. Core renders it with pdf.js
 * against five new `laravel-crm.{type}.preview` routes; the panel can use
 * neither.
 *
 * The routes sit inside `config('laravel-crm.user_interface')`, which this
 * plugin's own installer recommends turning off — the same reason
 * {@see PdfTemplatePreview} re-expresses core's TemplatePreviewController. So
 * the panel registers its own route, inside the panel's auth middleware, and
 * authorises it against the same policy ability the download twin needs:
 * preview grants nothing view did not.
 *
 * And the viewer is the browser's own, reached with an `<iframe>`, not pdf.js.
 * Core needs pdf.js because it renders into a page it fully controls; a plugin
 * that ships no Vite bundle would be taking on the worker-MIME class of bug
 * core spent 2.4.1 fixing to gain nothing a native PDF viewer does not already
 * do. Same bytes either way — both sides render {@see CrmPdf}'s output.
 */
class DocumentPreview
{
    /**
     * Doc type slug => the model behind it.
     *
     * The slug crosses the wire as a route parameter, so this doubles as the
     * whitelist that keeps a raw class name off the request. The keys are the
     * plugin's own `$type` spellings — the ones {@see CrmPdf} takes.
     *
     * @var array<string, class-string<Model>>
     */
    public const TYPES = [
        'quote' => Quote::class,
        'order' => Order::class,
        'invoice' => Invoice::class,
        'delivery' => Delivery::class,
        'purchaseorder' => PurchaseOrder::class,
    ];

    /**
     * The panel-relative route name. Registered per panel through
     * `Panel::authenticatedRoutes()`, so the resolved name is
     * `filament.{panel}.laravel-crm-filament.document-preview`.
     */
    public const ROUTE = 'laravel-crm-filament.document-preview';

    /**
     * The model class behind a slug, or null when the slug is unknown.
     */
    public static function modelFor(string $type): ?string
    {
        return self::TYPES[$type] ?? null;
    }

    /**
     * The doc type slug for a record, or null when it has no preview.
     *
     * Matched with instanceof rather than an exact class lookup so a host that
     * swaps in its own subclass still resolves.
     */
    public static function typeFor(Model $record): ?string
    {
        foreach (self::TYPES as $type => $class) {
            if ($record instanceof $class) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Whether the current panel registers the preview route.
     *
     * Guards the action the same way {@see PortalUrl::exists()} guards the
     * portal links: a host that builds a panel without this plugin's routes
     * should hide the button, not render one that 404s.
     */
    public static function available(): bool
    {
        return Filament::getCurrentOrDefaultPanel() !== null
            && app('router')->has(self::routeName());
    }

    /**
     * The URL the slide-over's iframe loads, or null when the record has no
     * preview or the route is not registered.
     */
    public static function urlFor(Model $record): ?string
    {
        $type = self::typeFor($record);

        if ($type === null || ! self::available()) {
            return null;
        }

        return route(self::routeName(), [
            'type' => $type,
            'record' => $record->getAttribute('external_id') ?? $record->getRouteKey(),
        ]);
    }

    /**
     * The fully-qualified route name for the current panel.
     */
    public static function routeName(): string
    {
        // Current-or-default, not current: an action is built outside a panel
        // request often enough (a table assembled in a test, a queued render)
        // that keying off the *current* panel alone would hide the button on a
        // single-panel install for no reason the user could see.
        $panel = Filament::getCurrentOrDefaultPanel();

        return 'filament.' . ($panel?->getId() ?? 'admin') . '.' . self::ROUTE;
    }
}
