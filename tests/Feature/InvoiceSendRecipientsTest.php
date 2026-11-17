<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportException;
use VentureDrake\LaravelCrm\Mail\SendInvoice;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\EditInvoice;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\ListInvoices;

use function Pest\Livewire\livewire;

/**
 * Core 2.7.0's InvoiceSend: the To field takes a comma or semicolon separated
 * list, a bad address is a field error, a transport failure is shown rather
 * than a 500, and a sent invoice is flagged `sent`.
 */
beforeEach(function () {
    actingAsCrmAdmin();

    $this->invoice = Invoice::create([
        'external_id' => (string) Str::uuid(),
        'currency' => 'USD',
        'total' => 110,
    ]);
    $this->invoice->refresh();
});

function sendInvoiceFromList(Invoice $invoice, string $to): mixed
{
    return livewire(ListInvoices::class)
        ->callAction(TestAction::make('send')->table($invoice), data: [
            'to' => $to,
            'subject' => 'Invoice ' . $invoice->invoice_id,
            'message' => 'Please find your invoice attached.',
        ]);
}

it('mails every address in a comma or semicolon separated list and marks the invoice sent', function () {
    Mail::fake();

    sendInvoiceFromList($this->invoice, 'a@example.com, b@example.com; c@example.com;')
        ->assertHasNoActionErrors()
        ->assertNotified(ucfirst(__('laravel-crm::lang.invoice_sent')));

    Mail::assertSent(SendInvoice::class, fn (SendInvoice $mail): bool => $mail->emailTo === [
        'a@example.com',
        'b@example.com',
        'c@example.com',
    ]);

    expect((int) $this->invoice->fresh()->sent)->toBe(1);
});

it('refuses an invalid address as a field error', function () {
    Mail::fake();

    sendInvoiceFromList($this->invoice, 'a@example.com, not-an-email')
        ->assertHasActionErrors(['to']);

    Mail::assertNothingSent();
    expect((int) $this->invoice->fresh()->sent)->toBe(0);
});

it('shows a transport failure and leaves the invoice unsent', function () {
    Mail::shouldReceive('send')
        ->once()
        ->andThrow(new TransportException('Connection refused'));

    sendInvoiceFromList($this->invoice, 'a@example.com')
        ->assertNotified(ucfirst(__('laravel-crm::lang.email_could_not_be_sent', [
            'reason' => 'Connection refused',
        ])));

    expect((int) $this->invoice->fresh()->sent)->toBe(0);
});

it('sends from the invoice page header through the same action', function () {
    Mail::fake();

    livewire(EditInvoice::class, ['record' => $this->invoice->external_id])
        ->callAction('send', data: [
            'to' => 'a@example.com;b@example.com',
            'subject' => 'Invoice',
            'message' => 'Attached.',
        ])
        ->assertHasNoActionErrors();

    Mail::assertSent(SendInvoice::class, fn (SendInvoice $mail): bool => $mail->emailTo === ['a@example.com', 'b@example.com']);
    expect((int) $this->invoice->fresh()->sent)->toBe(1);
});
