<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Delivery;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\PurchaseOrder;
use VentureDrake\LaravelCrm\Models\Quote;
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

    // Core defines no contact-details setting for these three, so the block is
    // present-and-null rather than absent.
    foreach (['quote', 'order', 'delivery'] as $type) {
        $data = CrmPdf::viewData($type, pdfRecordFor($type));

        expect($data)->toHaveKeys(['taxName', 'contactDetails', 'paymentInstructions'])
            ->and($data['contactDetails'])->toBeNull();
    }
});
