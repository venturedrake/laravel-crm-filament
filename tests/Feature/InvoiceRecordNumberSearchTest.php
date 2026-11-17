<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\InvoiceResource;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\ListInvoices;

use function Pest\Livewire\livewire;

/**
 * Core 2.7.0's RecordSearch on the invoice list: the number matches as
 * "INV-1001" or "1001", an invoice keeps answering to the prefix it was
 * created with, and the reference is matched literally.
 */
beforeEach(function () {
    actingAsCrmAdmin();

    Setting::create(['external_id' => (string) Str::uuid(), 'name' => 'invoice_prefix', 'value' => 'INV-']);
});

function numberedInvoice(?string $prefix, int $number, ?string $reference = null): Invoice
{
    $invoice = Invoice::create(['external_id' => (string) Str::uuid(), 'reference' => $reference]);

    $invoice->forceFill([
        'prefix' => $prefix,
        'number' => $number,
        // invoice_id is NOT NULL: an invoice made before a prefix was set
        // is stored as its bare number.
        'invoice_id' => ($prefix ?? '') . $number,
    ])->saveQuietly();

    return $invoice->fresh();
}

it('finds an invoice by its full number or the digits alone', function () {
    $current = numberedInvoice('INV-', 1001);
    $other = numberedInvoice('INV-', 1002);
    $oldPrefix = numberedInvoice('OLD-', 1001);

    livewire(ListInvoices::class)
        ->searchTable('INV-1001')
        ->assertCanSeeTableRecords([$current])
        ->assertCanNotSeeTableRecords([$other, $oldPrefix]);

    livewire(ListInvoices::class)
        ->searchTable('inv-1001')
        ->assertCanSeeTableRecords([$current])
        ->assertCanNotSeeTableRecords([$other, $oldPrefix]);

    livewire(ListInvoices::class)
        ->searchTable('1001')
        ->assertCanSeeTableRecords([$current, $oldPrefix])
        ->assertCanNotSeeTableRecords([$other]);

    livewire(ListInvoices::class)
        ->searchTable('OLD-1001')
        ->assertCanSeeTableRecords([$oldPrefix])
        ->assertCanNotSeeTableRecords([$current, $other]);
});

it('finds an invoice made before a prefix was set by the current prefix', function () {
    $unprefixed = numberedInvoice(null, 1003);
    $other = numberedInvoice('INV-', 1002);

    livewire(ListInvoices::class)
        ->searchTable('INV-1003')
        ->assertCanSeeTableRecords([$unprefixed])
        ->assertCanNotSeeTableRecords([$other]);
});

it('matches the reference literally', function () {
    $underscore = numberedInvoice('INV-', 1001, 'PO_77');
    $lookalike = numberedInvoice('INV-', 1002, 'POX77');

    livewire(ListInvoices::class)
        ->searchTable('PO_77')
        ->assertCanSeeTableRecords([$underscore])
        ->assertCanNotSeeTableRecords([$lookalike]);

    livewire(ListInvoices::class)
        ->searchTable('po_77')
        ->assertCanSeeTableRecords([$underscore])
        ->assertCanNotSeeTableRecords([$lookalike]);
});

it('keeps global search on the invoice number and reference', function () {
    $current = numberedInvoice('INV-', 1001, 'Spring order');
    numberedInvoice('INV-', 1002, 'Autumn order');

    $byNumber = InvoiceResource::getGlobalSearchResults('INV-1001');
    $byReference = InvoiceResource::getGlobalSearchResults('Spring');

    expect($byNumber)->toHaveCount(1)
        ->and($byNumber->first()->title)->toBe('INV-1001')
        ->and($byReference)->toHaveCount(1)
        ->and($byReference->first()->title)->toBe($current->invoice_id);
});
