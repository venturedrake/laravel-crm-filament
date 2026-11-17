<?php

use Barryvdh\DomPDF\ServiceProvider as DomPdfServiceProvider;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportException;
use VentureDrake\LaravelCrm\Mail\SendPurchaseOrder;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\PurchaseOrder;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;

use function Pest\Livewire\livewire;

/**
 * Core 2.7.0's PurchaseOrderSend: the To field takes several addresses, each
 * checked; a transport failure is a danger notification with the modal kept
 * open rather than a 500; and a send that went sets `sent`, which nothing
 * else did, so the Sent column never showed. The row action and the View /
 * Edit page actions are one implementation; the row and the Edit page are
 * driven here (the View page's crm-view-tabs blade trips Livewire's child-tag
 * check in this harness, see OrderConversionTotalsTest).
 */
beforeEach(function () {
    $this->app->register(DomPdfServiceProvider::class);

    actingAsCrmAdmin();

    Setting::updateOrCreate(['name' => 'currency'], ['external_id' => (string) Str::uuid(), 'value' => 'USD']);
    app('laravel-crm.settings')->set('organization_name', 'Test Organization');
    app('laravel-crm.settings')->forgetCache();

    $this->purchaseOrder = PurchaseOrder::create([
        'external_id' => (string) Str::uuid(),
        'reference' => 'To send',
        'currency' => 'USD',
    ]);
});

function sendPurchaseOrderFrom(string $where, PurchaseOrder $purchaseOrder, array $data)
{
    return match ($where) {
        'table' => livewire(ListPurchaseOrders::class)
            ->callAction(TestAction::make('send')->table($purchaseOrder), $data),
        'edit' => livewire(EditPurchaseOrder::class, ['record' => $purchaseOrder->external_id])
            ->callAction('send', $data),
    };
}

it('starts unsent', function () {
    expect((bool) $this->purchaseOrder->fresh()->sent)->toBeFalse();
});

it('mails every address in the list and marks the purchase order sent', function (string $where) {
    Mail::fake();

    sendPurchaseOrderFrom($where, $this->purchaseOrder, [
        'to' => 'one@example.com; two@example.com, three@example.com',
        'subject' => 'PO',
        'message' => 'Please find it attached.',
    ])->assertHasNoFormErrors()->assertNotified(ucfirst(__('laravel-crm::lang.purchase_order_sent')));

    Mail::assertSent(SendPurchaseOrder::class, fn (SendPurchaseOrder $mail): bool => $mail->emailTo === [
        'one@example.com', 'two@example.com', 'three@example.com',
    ]);

    expect((bool) $this->purchaseOrder->fresh()->sent)->toBeTrue();
})->with(['table', 'edit']);

it('refuses an invalid address in the list as a field error', function (string $where) {
    Mail::fake();

    sendPurchaseOrderFrom($where, $this->purchaseOrder, [
        'to' => 'one@example.com, not-an-address',
        'subject' => 'PO',
        'message' => 'Please find it attached.',
    ])->assertHasFormErrors(['to']);

    Mail::assertNothingSent();
    expect((bool) $this->purchaseOrder->fresh()->sent)->toBeFalse();
})->with(['table', 'edit']);

it('shows a transport failure as a danger notification and leaves the purchase order unsent', function (string $where) {
    Mail::shouldReceive('send')->once()->andThrow(new TransportException('Connection refused'));

    sendPurchaseOrderFrom($where, $this->purchaseOrder, [
        'to' => 'supplier@example.com',
        'subject' => 'PO',
        'message' => 'Please find it attached.',
    ])->assertNotified(ucfirst(__('laravel-crm::lang.email_could_not_be_sent', ['reason' => 'Connection refused'])));

    expect((bool) $this->purchaseOrder->fresh()->sent)->toBeFalse();
})->with(['table', 'edit']);

it('fills the To field from the contact\'s primary email', function () {
    $person = Person::create([
        'external_id' => (string) Str::uuid(),
        'first_name' => 'Sam',
        'last_name' => 'Supplier',
    ]);
    $person->emails()->create([
        'external_id' => (string) Str::uuid(),
        'address' => 'sam@example.com',
        'primary' => 1,
        'type' => 'work',
    ]);
    $this->purchaseOrder->update(['person_id' => $person->id]);

    livewire(EditPurchaseOrder::class, ['record' => $this->purchaseOrder->external_id])
        ->mountAction('send')
        ->assertSchemaStateSet(['to' => 'sam@example.com']);
});
