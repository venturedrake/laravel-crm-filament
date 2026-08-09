<?php

namespace VentureDrake\LaravelCrmFilament\Support;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use VentureDrake\LaravelCrm\Support\PdfLogo;
use VentureDrake\LaravelCrm\Support\PdfTemplateRegistry;

/**
 * One place where a CRM document becomes a PDF.
 *
 * There were ten hardcoded `laravel-crm::{x}.pdf` view strings across two
 * parallel implementations — five in the Concerns/ViewRecord pages (instance
 * methods, because page header actions are instance-bound) and five in
 * duplicated static `render*PdfToDisk()` methods on the Resources (static,
 * because table row actions are). The split is real, so both call in here
 * rather than one being deleted.
 *
 * Consolidating is what makes core 2.4.0's per-record template picker work at
 * all: `PdfTemplateRegistry::viewForModel()` resolves record choice > settings
 * default > a view the host published and edited by hand, and a hardcoded
 * string bypasses all three.
 *
 * Two things here are load-bearing and easy to get wrong:
 *
 *  - The doc-type mapping. The plugin's `$type` strings are
 *    quote|invoice|order|delivery|purchaseorder (they name storage
 *    directories), but the registry's are hyphenated — `purchase-order` — and
 *    `settingKey()` keeps the hyphen (`pdf_template_purchase-order`). Passing
 *    the plugin's spelling straight through would silently ignore the admin's
 *    chosen template for purchase orders.
 *  - The storage path and filename strings, which are byte-identical to what
 *    they replace. The mailers look for their attachment at exactly that path.
 */
class CrmPdf
{
    /**
     * Plugin `$type` => the registry's doc type.
     *
     * @var array<string, string>
     */
    public const DOC_TYPES = [
        'quote' => 'quote',
        'invoice' => 'invoice',
        'order' => 'order',
        'delivery' => 'delivery',
        'purchaseorder' => 'purchase-order',
    ];

    /**
     * Plugin `$type` => the filename prefix, preserved verbatim.
     *
     * @var array<string, string>
     */
    public const FILENAME_PREFIXES = [
        'quote' => 'quote',
        'invoice' => 'invoice',
        'order' => 'order',
        'delivery' => 'delivery',
        'purchaseorder' => 'purchase-order',
    ];

    /**
     * The attribute carrying the human-facing document number.
     *
     * @var array<string, string>
     */
    public const NUMBER_ATTRIBUTES = [
        'quote' => 'quote_id',
        'invoice' => 'invoice_id',
        'order' => 'order_id',
        'delivery' => 'delivery_id',
        'purchaseorder' => 'purchase_order_id',
    ];

    public static function docTypeFor(string $type): string
    {
        return self::DOC_TYPES[$type] ?? $type;
    }

    /**
     * The Blade view for this record: its own pinned template when it has one,
     * else the Settings → Templates default, else a legacy view the host has
     * published and modified.
     */
    public static function viewFor(string $type, ?Model $record = null): string
    {
        return PdfTemplateRegistry::viewForModel(self::docTypeFor($type), $record);
    }

    /**
     * The settings key holding the "contact details" footer block, per doc
     * type. Core only defines the two; the rest render the block empty.
     *
     * @var array<string, string>
     */
    public const CONTACT_DETAILS_SETTINGS = [
        'invoice' => 'invoice_contact_details',
        'purchaseorder' => 'purchase_order_contact_details',
    ];

    /**
     * The variable set each PDF template expects, keyed by doc type.
     *
     * Every key here is supplied for every doc type, unconditionally. The
     * bundled templates reference `$taxName`, `$contactDetails`,
     * `$paymentInstructions` and the address pair *unguarded* — a missing key
     * is not a blank block, it is an "Undefined variable" ViewException that
     * takes down the download and the send-with-attachment path with it. Core's
     * own controllers only pass the subset each of its templates happened to
     * need, which is why several of them break the moment an admin picks a
     * different template; passing the superset is what makes the picker safe.
     *
     * @return array<string, mixed>
     */
    public static function viewData(string $type, Model $record): array
    {
        $settings = app('laravel-crm.settings');

        $common = [
            'dateFormat' => $settings->get('date_format', config('laravel-crm.date_format')),
            'taxName' => $settings->get('tax_name', 'Tax'),
            'contactDetails' => isset(self::CONTACT_DETAILS_SETTINGS[$type])
                ? $settings->get(self::CONTACT_DETAILS_SETTINGS[$type], null)
                : null,
            'paymentInstructions' => $type === 'invoice'
                ? $settings->get('invoice_payment_instructions', null)
                : null,
            'fromName' => $settings->get('organization_name'),
            // Not the raw logo_file path: DomPDF refuses http(s) URLs unless the
            // host sets dompdf.enable_remote (false by default), so every PDF
            // came out with a broken-image box. PdfLogo inlines the bytes.
            'logo' => PdfLogo::fromSettings(),
        ];

        return match ($type) {
            'quote' => array_merge($common, ['quote' => $record], self::contactData($record)),
            'invoice' => array_merge($common, ['invoice' => $record], self::contactData($record)),
            'order' => array_merge($common, ['order' => $record], self::contactData($record)),
            'delivery' => array_merge($common, [
                'delivery' => $record,
                'order' => $order = $record->order,
            ], self::contactData($order)),
            // The purchase order addresses the supplier rather than a CRM
            // contact, but the template still reads `$address` unguarded (it is
            // the `@elseif` fallback under `$organization_address`), so the
            // block is populated the same way core's own download() does.
            'purchaseorder' => array_merge($common, ['purchaseOrder' => $record], self::contactData($record)),
            default => array_merge($common, self::contactData(null)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected static function contactData(?Model $record): array
    {
        return [
            'email' => optional($record?->person)->getPrimaryEmail(),
            'phone' => optional($record?->person)->getPrimaryPhone(),
            'address' => optional($record?->person)->getPrimaryAddress(),
            'organization_address' => optional($record?->organization)->getPrimaryAddress(),
        ];
    }

    public static function filename(Model $record, string $type): string
    {
        $prefix = self::FILENAME_PREFIXES[$type] ?? $type;
        $numberAttribute = self::NUMBER_ATTRIBUTES[$type] ?? 'id';

        return $prefix . '-' . strtolower((string) ($record->{$numberAttribute} ?? $record->external_id)) . '.pdf';
    }

    /**
     * Render to `storage/app/laravel-crm/{type}/{id}/{prefix}-{number}.pdf` and
     * return the storage-relative path. The mailers attach exactly this path.
     *
     * The directory is created with File, not `Storage::makeDirectory()`, and
     * that is the whole point: DomPDF writes through `storage_path()`, but as
     * of Laravel 11 the `local` disk is rooted at `storage/app/private`. Those
     * are two different directories, so the Storage call was creating one place
     * and the save was failing in another with "Failed to open stream: No such
     * file or directory" — on every document, on any host that had not happened
     * to create `storage/app/laravel-crm/…` by other means.
     */
    public static function renderToDisk(Model $record, string $type): string
    {
        $relativeDir = 'laravel-crm/' . $type . '/' . $record->id;

        File::ensureDirectoryExists(storage_path('app/' . $relativeDir));

        $pdfRelative = 'app/' . $relativeDir . '/' . self::filename($record, $type);

        Pdf::setOption(['fontDir' => public_path('vendor/laravel-crm/fonts')])
            ->loadView(self::viewFor($type, $record), self::viewData($type, $record))
            ->save(storage_path($pdfRelative));

        return $pdfRelative;
    }

    public static function download(Model $record, string $type): BinaryFileResponse
    {
        $pdfRelative = self::renderToDisk($record, $type);

        return Response::download(storage_path($pdfRelative), self::filename($record, $type));
    }
}
