<?php

namespace VentureDrake\LaravelCrmFilament\Support;

use Barryvdh\DomPDF\Facade\Pdf;
use VentureDrake\LaravelCrm\Support\PdfLogo;
use VentureDrake\LaravelCrm\Support\PdfSampleData;
use VentureDrake\LaravelCrm\Support\PdfTemplateRegistry;

/**
 * Renders a preview PDF of any (docType × template slug) pair against
 * fabricated sample data, so an admin can see a template before pinning it.
 *
 * Core has this as `TemplatePreviewController`, but its `sampleData()` is
 * `protected` and its routes sit inside the `laravel-crm.user_interface` gate
 * behind `auth.laravel-crm` — they work in tests (which turn that flag on) and
 * 404 for exactly the headless hosts this plugin serves. So the logic is
 * re-expressed here against the *public* {@see PdfSampleData}, and the panel
 * serves it from a Filament Action rather than a route: panel-authenticated
 * for free, nothing new to register, nothing to 404.
 *
 * Every model {@see PdfSampleData} returns is unsaved, so a preview works on a
 * brand-new install with an empty database and never writes anything.
 *
 * Upstream fix (recommended): make `TemplatePreviewController::sampleData()` a
 * public `PdfSampleData::viewDataFor($docType)` so both front ends share it.
 */
class PdfTemplatePreview
{
    /**
     * The raw PDF bytes for `$docType` rendered with `$slug`, or null when
     * either is unknown.
     */
    public static function render(string $docType, string $slug): ?string
    {
        if (! in_array($docType, PdfTemplateRegistry::DOC_TYPES, true)) {
            return null;
        }

        if (! array_key_exists($slug, PdfTemplateRegistry::all())) {
            return null;
        }

        // setPaper() is copied verbatim from core's controller and is not
        // optional: the container document declares a fixed 18.6cm width, and
        // without an explicit A4 portrait DomPDF falls through to its default
        // paper and leaves ~40% of the page blank. It looks like a bug in the
        // template.
        return Pdf::setOption(['fontDir' => public_path('vendor/laravel-crm/fonts')])
            ->setPaper('a4', 'portrait')
            ->loadView(PdfTemplateRegistry::viewFor($docType, $slug), self::sampleData($docType))
            ->output();
    }

    public static function filename(string $docType, string $slug): string
    {
        return 'preview-' . $docType . '-' . $slug . '.pdf';
    }

    /**
     * The variable set each PDF template expects, against sample data.
     *
     * Settings-driven values fall back to sensible defaults so a preview works
     * on an install with no Setting rows at all.
     *
     * @return array<string, mixed>
     */
    public static function sampleData(string $docType): array
    {
        $settings = app('laravel-crm.settings');

        $common = [
            'dateFormat' => $settings->get('date_format', config('laravel-crm.date_format', 'M j, Y')),
            'taxName' => $settings->get('tax_name', 'Tax'),
            'contactDetails' => $settings->get('invoice_contact_details', null),
            'paymentInstructions' => $settings->get('invoice_payment_instructions', null),
            'fromName' => $settings->get('organization_name', 'Sample Organization'),
            'logo' => PdfLogo::fromSettings(),
            'email' => null,
            'phone' => null,
            'address' => PdfSampleData::address(),
            'organization_address' => PdfSampleData::address(),
        ];

        return match ($docType) {
            'invoice' => array_merge($common, ['invoice' => PdfSampleData::invoice()]),
            'order' => array_merge($common, ['order' => PdfSampleData::order()]),
            'purchase-order' => array_merge($common, ['purchaseOrder' => PdfSampleData::purchaseOrder()]),
            // The delivery blade also expects the parent order's shape.
            'delivery' => array_merge($common, [
                'delivery' => PdfSampleData::delivery(),
                'order' => PdfSampleData::order(),
            ]),
            'quote' => array_merge($common, ['quote' => PdfSampleData::quote()]),
            default => $common,
        };
    }

    /**
     * A template's picker thumbnail inlined as a data URI, memoised per
     * process.
     *
     * Core serves these from `GET /settings/templates/thumbnail/{slug}`, which
     * is inside the `user_interface` gate. Reading the file through the
     * registry (published copy first, packaged copy second) needs no route at
     * all, and the SVGs are small, identical for every user and carry no
     * tenant data.
     *
     * @var array<string, string|null>
     */
    protected static array $thumbnails = [];

    public static function thumbnail(string $slug): ?string
    {
        if (array_key_exists($slug, self::$thumbnails)) {
            return self::$thumbnails[$slug];
        }

        $path = PdfTemplateRegistry::thumbnailFile($slug);

        if ($path === null) {
            return self::$thumbnails[$slug] = null;
        }

        $contents = @file_get_contents($path);

        return self::$thumbnails[$slug] = $contents === false
            ? null
            : 'data:image/svg+xml;base64,' . base64_encode($contents);
    }

    /**
     * For tests, and for anything that republishes assets in-process.
     */
    public static function forgetThumbnails(): void
    {
        self::$thumbnails = [];
    }
}
