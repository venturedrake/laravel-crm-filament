<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\PurchaseOrder;
use VentureDrake\LaravelCrm\Support\PdfTemplateRegistry;
use VentureDrake\LaravelCrmFilament\Support\CrmPdf;

/**
 * Record choice > Settings default > shipped default, resolved through one
 * place instead of the ten hardcoded `laravel-crm::{x}.pdf` strings this
 * release replaced.
 */
beforeEach(function () {
    PdfTemplateRegistry::forgetPublishedOverrides();
});

it('maps the plugin doc types onto the registry spelling', function () {
    // The plugin's $type strings name storage directories; the registry's are
    // hyphenated. Getting `purchaseorder` -> `purchase-order` wrong silently
    // reads the wrong setting key and ignores the admin's choice.
    expect(CrmPdf::docTypeFor('purchaseorder'))->toBe('purchase-order')
        ->and(CrmPdf::docTypeFor('quote'))->toBe('quote')
        ->and(CrmPdf::docTypeFor('invoice'))->toBe('invoice')
        ->and(CrmPdf::docTypeFor('order'))->toBe('order')
        ->and(CrmPdf::docTypeFor('delivery'))->toBe('delivery');

    // …and the setting key keeps the hyphen.
    expect(PdfTemplateRegistry::settingKey(CrmPdf::docTypeFor('purchaseorder')))
        ->toBe('pdf_template_purchase-order');
});

it('falls back to the shipped default when nothing is set', function () {
    $invoice = Invoice::create(['external_id' => (string) Str::uuid(), 'invoice_id' => 'INV-1']);

    expect(CrmPdf::viewFor('invoice', $invoice))
        ->toBe('laravel-crm::pdfs.' . PdfTemplateRegistry::DEFAULT_SLUG . '.invoice');
});

it('uses the settings default when the record has pinned nothing', function () {
    app('laravel-crm.settings')->set('pdf_template_invoice', 'bold');
    app('laravel-crm.settings')->forgetCache();

    $invoice = Invoice::create(['external_id' => (string) Str::uuid(), 'invoice_id' => 'INV-2']);

    expect(CrmPdf::viewFor('invoice', $invoice))->toBe('laravel-crm::pdfs.bold.invoice');
});

it('lets the record override the settings default', function () {
    app('laravel-crm.settings')->set('pdf_template_invoice', 'bold');
    app('laravel-crm.settings')->forgetCache();

    $invoice = Invoice::create([
        'external_id' => (string) Str::uuid(),
        'invoice_id' => 'INV-3',
        'pdf_template' => 'compact',
    ]);

    expect(CrmPdf::viewFor('invoice', $invoice))->toBe('laravel-crm::pdfs.compact.invoice');
});

it('reads the hyphenated setting key for purchase orders', function () {
    // The bug this guards: writing pdf_template_purchase-order and reading
    // pdf_template_purchaseorder means the admin's choice is silently ignored
    // for exactly one of the five doc types.
    app('laravel-crm.settings')->set('pdf_template_purchase-order', 'professional');
    app('laravel-crm.settings')->forgetCache();

    $po = PurchaseOrder::create(['external_id' => (string) Str::uuid()]);

    expect(CrmPdf::viewFor('purchaseorder', $po))
        ->toBe('laravel-crm::pdfs.professional.purchase-order');
});

it('ignores a slug that no longer ships rather than 500ing the download', function () {
    $invoice = Invoice::create([
        'external_id' => (string) Str::uuid(),
        'invoice_id' => 'INV-4',
        'pdf_template' => 'a-template-that-was-removed',
    ]);

    expect(CrmPdf::viewFor('invoice', $invoice))
        ->toBe('laravel-crm::pdfs.' . PdfTemplateRegistry::DEFAULT_SLUG . '.invoice');
});

it('keeps the storage path and filename byte-identical to what the mailers expect', function () {
    $invoice = Invoice::create([
        'external_id' => (string) Str::uuid(),
        'invoice_id' => 'INV-0042',
    ]);

    // The observer mints invoice_id, so compare against what it actually set.
    expect(CrmPdf::filename($invoice, 'invoice'))
        ->toBe('invoice-' . strtolower((string) $invoice->fresh()->invoice_id) . '.pdf');

    $po = PurchaseOrder::create([
        'external_id' => (string) Str::uuid(),
        'purchase_order_id' => 'PO-7',
    ]);

    // The prefix is hyphenated here even though the storage directory is not.
    expect(CrmPdf::filename($po, 'purchaseorder'))
        ->toStartWith('purchase-order-')
        ->toEndWith('.pdf');
});

it('no longer hardcodes a pdf view path anywhere in src', function () {
    $hits = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src'));

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        // A hardcoded view bypasses the record's pinned template, the settings
        // default and the published-override fallback all at once.
        if (preg_match("/loadView\(\s*'laravel-crm::/", $source)) {
            $hits[] = $file->getPathname();
        }
    }

    expect($hits)->toBe([]);
});
