<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\InvoiceLine;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\EditInvoice;

use function Pest\Livewire\livewire;

/**
 * Core 2.6.0: InvoiceService::update() looked up an existing line's tax rate
 * with the line's own id instead of its product id. Saving an invoice from the
 * panel then gave the line the tax of whichever product shared the line's id.
 */
beforeEach(function () {
    actingAsCrmAdmin();

    Setting::create(['external_id' => (string) Str::uuid(), 'name' => 'currency', 'value' => 'USD']);
});

it('keeps the line\'s own product tax when an invoice is edited', function () {
    // tax_rate is not fillable on Product.
    $decoy = Product::create(['external_id' => (string) Str::uuid(), 'name' => 'Decoy', 'active' => true]);
    $decoy->forceFill(['tax_rate' => 5])->save();
    $taxed = Product::create(['external_id' => (string) Str::uuid(), 'name' => 'Taxed', 'active' => true]);
    $taxed->forceFill(['tax_rate' => 10])->save();

    $invoice = Invoice::create(['external_id' => (string) Str::uuid(), 'currency' => 'USD']);

    // The line's id is the decoy product's id: the case the old lookup got wrong.
    $line = InvoiceLine::create([
        'id' => $decoy->id,
        'external_id' => (string) Str::uuid(),
        'invoice_id' => $invoice->id,
        'product_id' => $taxed->id,
        'quantity' => 1,
        'price' => 10000,
        'amount' => 10000,
        'tax_rate' => 10,
        'tax_amount' => 1000,
        'currency' => 'USD',
    ]);

    livewire(EditInvoice::class, ['record' => $invoice->external_id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((float) $line->fresh()->tax_rate)->toBe(10.0);
});
