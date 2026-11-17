<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\InvoiceLine;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\EditInvoice;

use function Pest\Livewire\livewire;

/**
 * Core 2.7.0: once a payment is recorded an invoice's total is fixed, and
 * InvoiceService refuses an edit that reprices it (`line_items`). The panel
 * shows that refusal instead of a save that silently does nothing.
 */
beforeEach(function () {
    actingAsCrmAdmin();

    Setting::create(['external_id' => (string) Str::uuid(), 'name' => 'currency', 'value' => 'USD']);

    $product = Product::create(['external_id' => (string) Str::uuid(), 'name' => 'Widget', 'active' => true]);
    $product->forceFill(['tax_rate' => 10])->save();

    // 100.00 + 10.00 tax, 50.00 of it paid. The setters take units.
    $this->invoice = Invoice::create([
        'external_id' => (string) Str::uuid(),
        'currency' => 'USD',
        'reference' => 'Before',
        'subtotal' => 100,
        'tax' => 10,
        'total' => 110,
        'amount_paid' => 50,
        'amount_due' => 60,
    ]);

    $this->line = InvoiceLine::create([
        'external_id' => (string) Str::uuid(),
        'invoice_id' => $this->invoice->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'price' => 100,
        'amount' => 100,
        'tax_rate' => 10,
        'tax_amount' => 10,
        'currency' => 'USD',
    ]);
});

it('refuses to reprice a part-paid invoice and changes nothing', function () {
    $component = livewire(EditInvoice::class, ['record' => $this->invoice->external_id]);
    $key = array_key_first($component->get('data.products'));

    $component
        ->set('data.reference', 'After')
        ->set("data.products.{$key}.unit_price", 150)
        ->call('save')
        ->assertNotified(ucfirst(__('laravel-crm::lang.invoice_total_locked_after_payment')));

    $invoice = $this->invoice->fresh();

    expect((int) $this->line->fresh()->getRawOriginal('price'))->toBe(10000)
        ->and((int) $invoice->getRawOriginal('total'))->toBe(11000)
        ->and((int) $invoice->getRawOriginal('amount_paid'))->toBe(5000)
        // Rolled back with the rest of the save.
        ->and($invoice->reference)->toBe('Before');
});

it('saves an edit that leaves the total as it was', function () {
    livewire(EditInvoice::class, ['record' => $this->invoice->external_id])
        ->set('data.reference', 'After')
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotNotified(ucfirst(__('laravel-crm::lang.invoice_total_locked_after_payment')));

    $invoice = $this->invoice->fresh();

    expect($invoice->reference)->toBe('After')
        ->and((int) $invoice->getRawOriginal('total'))->toBe(11000)
        ->and((int) $this->line->fresh()->getRawOriginal('price'))->toBe(10000);
});
