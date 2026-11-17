<?php

use Filament\Actions\Testing\TestAction;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrmFilament\Models\InvoicePayment;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\InvoiceResource;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\EditInvoice;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\ListInvoices;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\ViewInvoice;
use VentureDrake\LaravelCrmFilament\Support\MoneyForm;

use function Pest\Livewire\livewire;

/**
 * Core 2.7.0 (InvoicePay::pay()): an invoice can be paid in parts, up to what
 * is outstanding, with the balance re-checked under a row lock, and Pay stays
 * on offer until nothing is left.
 */
beforeEach(function () {
    actingAsCrmAdmin();
});

function partPaymentInvoice(float $total = 100.0): Invoice
{
    $invoice = Invoice::create([
        'external_id' => (string) Str::uuid(),
        'currency' => 'USD',
    ]);
    $invoice->total = $total; // the setter stores cents
    $invoice->save();

    return $invoice->fresh();
}

function payInvoiceFromList(Invoice $invoice, float $amount, string $paidAt = '2026-05-27'): mixed
{
    return livewire(ListInvoices::class)
        ->callAction(TestAction::make('markPaid')->table($invoice), data: [
            'amount' => $amount,
            'paid_at' => $paidAt,
        ]);
}

it('takes two part payments and marks the invoice paid on the last', function () {
    $invoice = partPaymentInvoice(100.0);

    payInvoiceFromList($invoice, 40.0)
        ->assertHasNoActionErrors()
        ->assertNotified(ucfirst(__('laravel-crm::lang.invoice_payment_recorded', [
            'amount' => MoneyForm::display(4000, 'USD'),
            'due' => MoneyForm::display(6000, 'USD'),
        ])));

    $invoice->refresh();
    expect((int) $invoice->getRawOriginal('amount_paid'))->toBe(4000)
        ->and((int) $invoice->getRawOriginal('amount_due'))->toBe(6000)
        ->and($invoice->fully_paid_at)->toBeNull()
        ->and(InvoiceResource::balanceDueCents($invoice))->toBe(6000)
        ->and(InvoiceResource::isPartPaid($invoice))->toBeTrue();

    payInvoiceFromList($invoice, 60.0, '2026-06-03')
        ->assertHasNoActionErrors()
        ->assertNotified(ucfirst(__('laravel-crm::lang.invoice_paid')));

    $invoice->refresh();
    expect((int) $invoice->getRawOriginal('amount_paid'))->toBe(10000)
        ->and((int) $invoice->getRawOriginal('amount_due'))->toBe(0)
        ->and($invoice->fully_paid_at?->toDateString())->toBe('2026-06-03')
        ->and(InvoiceResource::balanceDueCents($invoice))->toBe(0)
        ->and(InvoiceResource::isPartPaid($invoice))->toBeFalse()
        ->and(InvoicePayment::where('invoice_id', $invoice->id)->pluck('amount')->all())->toBe([4000, 6000]);
});

it('adds part payments up exactly in cents', function () {
    $invoice = partPaymentInvoice(100.0);

    foreach ([33.33, 33.33, 33.34] as $amount) {
        payInvoiceFromList($invoice->fresh(), $amount)->assertHasNoActionErrors();
    }

    $invoice->refresh();
    expect((int) $invoice->getRawOriginal('amount_paid'))->toBe(10000)
        ->and($invoice->fully_paid_at)->not->toBeNull();
});

it('refuses a payment above the balance in the form', function () {
    $invoice = partPaymentInvoice(100.0);
    payInvoiceFromList($invoice, 40.0);

    payInvoiceFromList($invoice->fresh(), 60.01)->assertHasActionErrors(['amount']);
    payInvoiceFromList($invoice->fresh(), 0)->assertHasActionErrors(['amount']);

    $invoice->refresh();
    expect((int) $invoice->getRawOriginal('amount_paid'))->toBe(4000)
        ->and(InvoicePayment::where('invoice_id', $invoice->id)->count())->toBe(1);
});

it('re-checks the amount against the locked balance', function () {
    $invoice = partPaymentInvoice(100.0);

    // A second tab opened the modal while the balance was still 100.00.
    $stale = $invoice->fresh();

    InvoiceResource::markPaidAction()
        ->record($invoice->fresh())
        ->call(['data' => ['amount' => 60.0, 'paid_at' => '2026-05-27']]);

    expect(fn () => InvoiceResource::markPaidAction()
        ->record($stale)
        ->call(['data' => ['amount' => 60.0, 'paid_at' => '2026-05-27']]))
        ->toThrow(Halt::class);

    $invoice->refresh();
    expect((int) $invoice->getRawOriginal('amount_paid'))->toBe(6000)
        ->and($invoice->fully_paid_at)->toBeNull()
        ->and(InvoicePayment::where('invoice_id', $invoice->id)->count())->toBe(1);
});

it('keeps Pay on offer after a part payment and hides it once paid', function () {
    $invoice = partPaymentInvoice(100.0);

    livewire(ListInvoices::class)->assertActionVisible(TestAction::make('markPaid')->table($invoice));

    payInvoiceFromList($invoice, 40.0);
    $invoice->refresh();

    livewire(ListInvoices::class)->assertActionVisible(TestAction::make('markPaid')->table($invoice));
    livewire(EditInvoice::class, ['record' => $invoice->external_id])->assertActionVisible('markPaid');

    payInvoiceFromList($invoice, 60.0);
    $invoice->refresh();

    livewire(ListInvoices::class)->assertActionHidden(TestAction::make('markPaid')->table($invoice));
    livewire(EditInvoice::class, ['record' => $invoice->external_id])->assertActionHidden('markPaid');
});

it('hides Pay on an invoice marked paid with nothing recorded against it', function () {
    $invoice = partPaymentInvoice(100.0);
    $invoice->forceFill(['fully_paid_at' => now()])->save();

    expect(InvoiceResource::canRecordPayment($invoice->fresh()))->toBeFalse()
        ->and(InvoiceResource::balanceDueCents($invoice->fresh()))->toBe(0);
});

it('shows the balance due rather than a stale stored amount_due', function () {
    $invoice = partPaymentInvoice(100.0);
    // An older invoice: paid 25.00 but amount_due never set. The setters
    // take units and store cents.
    $invoice->forceFill(['amount_paid' => 25, 'amount_due' => null])->saveQuietly();

    $columns = livewire(ListInvoices::class)->instance()->getTable()->getColumns();

    expect((int) $columns['amount_due']->record($invoice->fresh())->getState())->toBe(7500);
});

it('badges a part-paid invoice', function () {
    $invoice = partPaymentInvoice(100.0);

    $heading = function (Invoice $record): string {
        $page = (new ReflectionClass(ViewInvoice::class))->newInstanceWithoutConstructor();
        $page->record = $record;

        return (string) $page->getHeading();
    };

    $badge = e(ucfirst(__('laravel-crm::lang.part_paid')));

    expect($heading($invoice))->not->toContain($badge);

    payInvoiceFromList($invoice, 40.0);
    expect($heading($invoice->fresh()))->toContain($badge);

    payInvoiceFromList($invoice->fresh(), 60.0);
    expect($heading($invoice->fresh()))->not->toContain($badge);
});
