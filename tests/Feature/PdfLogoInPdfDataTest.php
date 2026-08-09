<?php

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Support\PdfLogo;
use VentureDrake\LaravelCrmFilament\Support\CrmPdf;
use VentureDrake\LaravelCrmFilament\Support\LogoUrl;
use VentureDrake\LaravelCrmFilament\Support\PdfTemplatePreview;

/**
 * DomPDF refuses http(s) URLs unless the host sets `dompdf.enable_remote`,
 * which is false by default — so passing the raw `logo_file` path put a
 * broken-image box where the logo should be on every PDF. PdfLogo inlines the
 * file bytes instead, which needs no host configuration.
 *
 * Only the PDF paths go through it: a browser fetches asset('storage/...')
 * perfectly well, and inlining there would bloat every page load.
 */
beforeEach(function () {
    Storage::fake('public');
});

it('inlines the logo as a data URI for PDF view data', function () {
    Storage::disk('public')->put('laravel-crm/logo.png', 'not-really-a-png');

    app('laravel-crm.settings')->set('logo_file', 'laravel-crm/logo.png');
    app('laravel-crm.settings')->forgetCache();

    $invoice = Invoice::create(['external_id' => (string) Str::uuid()]);

    $data = CrmPdf::viewData('invoice', $invoice);

    expect($data['logo'])->toStartWith('data:')
        ->and($data['logo'])->toContain(base64_encode('not-really-a-png'));
});

it('inlines the logo on previews too', function () {
    Storage::disk('public')->put('laravel-crm/logo.png', 'preview-bytes');

    app('laravel-crm.settings')->set('logo_file', 'laravel-crm/logo.png');
    app('laravel-crm.settings')->forgetCache();

    expect(PdfTemplatePreview::sampleData('invoice')['logo'])->toStartWith('data:');
});

it('renders the organisation name instead of a broken image when the file is gone', function () {
    app('laravel-crm.settings')->set('logo_file', 'laravel-crm/deleted.png');
    app('laravel-crm.settings')->forgetCache();

    $invoice = Invoice::create(['external_id' => (string) Str::uuid()]);

    expect(CrmPdf::viewData('invoice', $invoice)['logo'])->toBeNull()
        ->and(PdfLogo::src('laravel-crm/deleted.png'))->toBeNull();
});

it('passes null when no logo is configured at all', function () {
    app('laravel-crm.settings')->set('logo_file', null);
    app('laravel-crm.settings')->forgetCache();

    $invoice = Invoice::create(['external_id' => (string) Str::uuid()]);

    expect(CrmPdf::viewData('invoice', $invoice)['logo'])->toBeNull();
});

it('leaves the browser paths on asset() rather than inlining them', function () {
    Storage::disk('public')->put('laravel-crm/logo.png', 'browser-bytes');

    // LogoUrl is what the login screen, the panel brand and the portal use. A
    // base64 URI there would bloat every page load for no benefit.
    expect(LogoUrl::resolve('laravel-crm/logo.png'))
        ->toContain('storage/laravel-crm/logo.png')
        ->not->toStartWith('data:');

    foreach ([
        'src/Support/LogoUrl.php',
        'src/Auth/Login.php',
        'src/LaravelCrmPlugin.php',
    ] as $file) {
        expect((string) file_get_contents(dirname(__DIR__, 2) . '/' . $file))
            ->not->toContain('PdfLogo');
    }
});

it('uses PdfLogo everywhere a PDF is rendered', function () {
    foreach (['src/Support/CrmPdf.php', 'src/Support/PdfTemplatePreview.php'] as $file) {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);

        expect($source)->toContain('PdfLogo::')
            // The raw path is exactly what produced the broken-image box.
            ->not->toContain("'logo' => \$settings->get('logo_file')");
    }
});
