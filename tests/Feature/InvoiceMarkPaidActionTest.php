<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrmFilament\Models\InvoicePayment;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\InvoiceResource;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\ListInvoices;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

beforeEach(function () {
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'Mark Paid Tester',
        'email' => 'mark-paid-' . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->assignRole('Admin');
    $this->actingAs($this->user);
});

function markPaidInvoice(string $reference, float $total = 100.0): Invoice
{
    $invoice = Invoice::create([
        'external_id' => (string) Str::uuid(),
        'reference' => $reference,
    ]);
    $invoice->total = $total; // mutator stores cents
    $invoice->save();

    return $invoice->fresh();
}

it('updates amount_paid, amount_due and fully_paid_at and records a payment when the payments table exists', function () {
    $invoice = markPaidInvoice('MARK-PAID-TABLE');

    InvoiceResource::markPaidAction()
        ->record($invoice)
        ->call(['data' => ['amount' => 100.0, 'paid_at' => '2026-05-27']]);

    $invoice->refresh();

    expect((int) $invoice->getAttributes()['amount_paid'])->toBe(10000);
    expect((int) $invoice->getAttributes()['amount_due'])->toBe(0);
    expect($invoice->fully_paid_at)->not->toBeNull();

    $payments = InvoicePayment::where('invoice_id', $invoice->getKey())->get();
    expect($payments)->toHaveCount(1);
    expect($payments->first()->amount)->toBe(10000);
});

it('leaves fully_paid_at null and sets the remaining amount_due on a partial payment', function () {
    $invoice = markPaidInvoice('MARK-PAID-PARTIAL');

    InvoiceResource::markPaidAction()
        ->record($invoice)
        ->call(['data' => ['amount' => 40.0, 'paid_at' => '2026-05-27']]);

    $invoice->refresh();

    expect((int) $invoice->getAttributes()['amount_paid'])->toBe(4000);
    expect((int) $invoice->getAttributes()['amount_due'])->toBe(6000);
    expect($invoice->fully_paid_at)->toBeNull();
});

it('still updates invoice totals without throwing when the payments table has not been migrated', function () {
    $invoice = markPaidInvoice('MARK-PAID-NO-TABLE');

    Schema::drop(config('laravel-crm.db_table_prefix') . 'invoice_payments');
    expect(Schema::hasTable(config('laravel-crm.db_table_prefix') . 'invoice_payments'))->toBeFalse();

    InvoiceResource::markPaidAction()
        ->record($invoice)
        ->call(['data' => ['amount' => 100.0, 'paid_at' => '2026-05-27']]);

    $invoice->refresh();

    expect((int) $invoice->getAttributes()['amount_paid'])->toBe(10000);
    expect((int) $invoice->getAttributes()['amount_due'])->toBe(0);
    expect($invoice->fully_paid_at)->not->toBeNull();
});

it('accumulates repeated payments against the same invoice', function () {
    $invoice = markPaidInvoice('MARK-PAID-REPEAT');

    InvoiceResource::markPaidAction()
        ->record($invoice)
        ->call(['data' => ['amount' => 30.0, 'paid_at' => '2026-05-27']]);

    InvoiceResource::markPaidAction()
        ->record($invoice->fresh())
        ->call(['data' => ['amount' => 70.0, 'paid_at' => '2026-05-28']]);

    $invoice->refresh();

    expect((int) $invoice->getAttributes()['amount_paid'])->toBe(10000);
    expect((int) $invoice->getAttributes()['amount_due'])->toBe(0);
    expect($invoice->fully_paid_at)->not->toBeNull();
    expect(InvoicePayment::where('invoice_id', $invoice->getKey())->count())->toBe(2);
});

it('opens with the balance and labels the date as the payment date', function () {
    $invoice = markPaidInvoice('MARK-PAID-LABEL');
    $invoice->amount_paid = 40;
    $invoice->save();

    livewire(ListInvoices::class)
        ->mountAction(TestAction::make('markPaid')->table($invoice->fresh()))
        ->assertActionDataSet(['amount' => 60.0]);

    // It read "Due date" before.
    $source = (string) file_get_contents(__DIR__ . '/../../src/Resources/Invoices/InvoiceResource.php');
    expect($source)->toMatch("/DatePicker::make\\('paid_at'\\)\\s*->label\\(__\\('laravel-crm-filament::labels\\.money\\.payment_date'\\)\\)/");
    expect(trans('laravel-crm-filament::labels.money.payment_date'))->toBe('Payment date');
});
