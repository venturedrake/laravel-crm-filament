<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Delivery;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\PurchaseOrder;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrm\Support\PdfContactDetails;
use VentureDrake\LaravelCrm\Support\PdfTemplateRegistry;
use VentureDrake\LaravelCrmFilament\Support\CrmPdf;

/**
 * The one thing PdfTemplateResolutionTest cannot tell you: whether the view it
 * resolves actually *renders*.
 *
 * That gap shipped a broken build once already. `CrmPdf::viewData()` omitted
 * `$taxName`, `$contactDetails` and `$paymentInstructions`, which the bundled
 * templates read unguarded — so every Download PDF action and every Send
 * action threw a ViewException on a default install while
 * PdfTemplateResolutionTest (view strings only) and TemplateSettingsPageTest
 * (which renders through PdfTemplatePreview, a *different* data builder) both
 * stayed green.
 *
 * So these tests call renderToDisk() — the real path behind both the header
 * action and the mail attachment — and they do it for every doc type against
 * every shipped template, because the picker means any pair can be live.
 */
beforeEach(function () {
    PdfTemplateRegistry::forgetPublishedOverrides();
});

// Not Storage::fake(): renderToDisk() writes through storage_path() rather than
// a disk, so a fake would silently sidestep the very thing under test. It writes
// for real, into testbench's scratch storage, and cleans up after itself.
afterEach(function () {
    File::deleteDirectory(storage_path('app/laravel-crm'));
});

/**
 * A saved record of each type, populated the way the create forms populate one.
 *
 * The dates are not optional decoration: the shipped quote, invoice and
 * purchase-order templates call `->format()` on them without a null check, so a
 * fixture without them would fail for a reason that has nothing to do with what
 * is under test.
 */
function pdfRecordFor(string $type)
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

it('renders a PDF for every doc type on a default install', function (string $type) {
    $relative = CrmPdf::renderToDisk(pdfRecordFor($type), $type);

    $path = storage_path($relative);

    expect(file_exists($path))->toBeTrue()
        ->and(file_get_contents($path))->toStartWith('%PDF');
})->with(['quote', 'invoice', 'order', 'delivery', 'purchaseorder']);

it('renders every doc type against every shipped template', function (string $type) {
    $record = pdfRecordFor($type);

    foreach (array_keys(PdfTemplateRegistry::all()) as $slug) {
        app('laravel-crm.settings')->set(
            PdfTemplateRegistry::settingKey(CrmPdf::docTypeFor($type)),
            $slug,
        );
        app('laravel-crm.settings')->forgetCache();

        $path = storage_path(CrmPdf::renderToDisk($record, $type));

        expect(file_get_contents($path))
            ->toStartWith('%PDF', "{$type} failed to render with the '{$slug}' template");
    }
})->with(['quote', 'invoice', 'order', 'delivery', 'purchaseorder']);

it('supplies the settings-driven variables the templates read unguarded', function () {
    app('laravel-crm.settings')->set('tax_name', 'VAT');
    app('laravel-crm.settings')->set('invoice_contact_details', 'Call us on 123');
    app('laravel-crm.settings')->set('invoice_payment_instructions', 'Pay to acct 456');
    app('laravel-crm.settings')->set('purchase_order_contact_details', 'Supplier desk');
    app('laravel-crm.settings')->forgetCache();

    $invoiceData = CrmPdf::viewData('invoice', pdfRecordFor('invoice'));

    expect($invoiceData['taxName'])->toBe('VAT')
        ->and($invoiceData['contactDetails'])->toBe('Call us on 123')
        ->and($invoiceData['paymentInstructions'])->toBe('Pay to acct 456');

    // Each doc type reads its own contact-details setting, and the purchase
    // order carries the address block its template dereferences unguarded.
    $poData = CrmPdf::viewData('purchaseorder', pdfRecordFor('purchaseorder'));

    expect($poData['contactDetails'])->toBe('Supplier desk')
        ->and($poData)->toHaveKeys(['address', 'organization_address', 'email', 'phone']);

    // With no override of their own and no shared value set, these three
    // resolve the block as present-and-null rather than absent — the key is
    // always passed, because the templates read it unguarded.
    foreach (['quote', 'order', 'delivery'] as $type) {
        $data = CrmPdf::viewData($type, pdfRecordFor($type));

        expect($data)->toHaveKeys(['taxName', 'contactDetails', 'paymentInstructions'])
            ->and($data['contactDetails'])->toBeNull();
    }
});

/**
 * The shared `pdf_contact_details` block, core 2.4.1.
 *
 * Before it, the plugin carried a two-entry map of its own — invoice and
 * purchase order — so a quote, order or delivery PDF downloaded from the panel
 * printed an *empty* From block on `modern` (the default) and on bold, compact
 * and professional. Rendering the blade, not just inspecting viewData(), is the
 * point: it is the templates that decide whether the value reaches paper.
 */
it('prints the shared From block on every doc type whose default template renders one', function () {
    app('laravel-crm.settings')->set(PdfContactDetails::SHARED_KEY, 'Shared From block');
    app('laravel-crm.settings')->forgetCache();

    foreach (['quote', 'invoice', 'order', 'delivery'] as $type) {
        $record = pdfRecordFor($type);
        $data = CrmPdf::viewData($type, $record);

        expect($data['contactDetails'])->toBe('Shared From block', $type);

        $html = view(CrmPdf::viewFor($type, $record), $data)->render();

        expect(str_contains($html, 'Shared From block'))
            ->toBeTrue("{$type} rendered no From block on the default template");
    }
});

it('prefers a doc-type override over the shared block, and treats a cleared override as unset', function () {
    $settings = app('laravel-crm.settings');

    $settings->set(PdfContactDetails::SHARED_KEY, 'Shared From block');
    $settings->set('invoice_contact_details', 'Invoice-only block');
    // Note the spelling: the plugin's `purchaseorder` maps to the registry's
    // `purchase-order`, which snake_cases to `purchase_order_contact_details`.
    $settings->set('purchase_order_contact_details', 'Supplier desk');
    $settings->forgetCache();

    expect(CrmPdf::viewData('invoice', pdfRecordFor('invoice'))['contactDetails'])->toBe('Invoice-only block')
        ->and(CrmPdf::viewData('purchaseorder', pdfRecordFor('purchaseorder'))['contactDetails'])->toBe('Supplier desk');

    foreach (['quote', 'order', 'delivery'] as $type) {
        expect(CrmPdf::viewData($type, pdfRecordFor($type))['contactDetails'])
            ->toBe('Shared From block', $type);
    }

    // Clearing the override persists an empty row rather than deleting it, so
    // the invoice has to fall back to the shared value rather than print blank.
    $settings->set('invoice_contact_details', '');
    $settings->forgetCache();

    expect(CrmPdf::viewData('invoice', pdfRecordFor('invoice'))['contactDetails'])->toBe('Shared From block');
});
