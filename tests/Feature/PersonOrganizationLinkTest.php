<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Deal;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\PurchaseOrder;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Resources\Deals\Pages\CreateDeal;
use VentureDrake\LaravelCrmFilament\Resources\Deals\Pages\EditDeal;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\CreateInvoice;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\EditInvoice;
use VentureDrake\LaravelCrmFilament\Resources\Leads\Pages\CreateLead;
use VentureDrake\LaravelCrmFilament\Resources\Leads\Pages\EditLead;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\CreateOrder;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\EditOrder;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\CreateQuote;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\EditQuote;

/**
 * Core 2.5.0 (#94): a contact person and an organization saved together on a
 * lead, deal, quote, order, invoice or purchase order become related contacts
 * of each other. Core does it in its Livewire components, not its services,
 * so the panel's pages have to do it too.
 */
beforeEach(function () {
    actingAsCrmAdmin();

    Setting::create(['external_id' => (string) Str::uuid(), 'name' => 'currency', 'value' => 'USD']);

    $this->person = Person::create(['external_id' => (string) Str::uuid(), 'first_name' => 'Ada', 'last_name' => 'Lovelace']);
    $this->organization = Organization::create(['external_id' => (string) Str::uuid(), 'name' => 'Analytic Engines']);
});

function linkedBothWays(Person $person, Organization $organization): bool
{
    return $person->contacts()->where('entityable_type', $organization->getMorphClass())->where('entityable_id', $organization->id)->exists()
        && $organization->contacts()->where('entityable_type', $person->getMorphClass())->where('entityable_id', $person->id)->exists();
}

it('links the person and organization when a record is created with both', function (string $page, array $data) {
    $method = new ReflectionMethod($page, 'handleRecordCreation');
    $method->invoke(new $page, $data + [
        'person_id' => $this->person->id,
        'organization_id' => $this->organization->id,
        'currency' => 'USD',
        'products' => [],
    ]);

    expect(linkedBothWays($this->person, $this->organization))->toBeTrue();
})->with([
    'lead' => [CreateLead::class, ['title' => 'Lead']],
    'deal' => [CreateDeal::class, ['title' => 'Deal']],
    'quote' => [CreateQuote::class, ['title' => 'Quote']],
    'order' => [CreateOrder::class, []],
    'invoice' => [CreateInvoice::class, []],
    'purchase order' => [CreatePurchaseOrder::class, []],
]);

it('links them when an existing record is saved with both, without duplicating the link', function (string $page, Closure $record) {
    $model = $record();
    $method = new ReflectionMethod($page, 'handleRecordUpdate');

    foreach ([1, 2] as $save) {
        $method->invoke(new $page, $model->fresh(), [
            'title' => 'Saved',
            'currency' => 'USD',
            'person_id' => $this->person->id,
            'organization_id' => $this->organization->id,
            'products' => [],
        ]);
    }

    expect(linkedBothWays($this->person, $this->organization))->toBeTrue()
        ->and($this->person->contacts()->count())->toBe(1)
        ->and($this->organization->contacts()->count())->toBe(1);
})->with([
    'lead' => [EditLead::class, fn () => Lead::create(['external_id' => (string) Str::uuid(), 'title' => 'Lead'])],
    'deal' => [EditDeal::class, fn () => Deal::create(['external_id' => (string) Str::uuid(), 'title' => 'Deal'])],
    'quote' => [EditQuote::class, fn () => Quote::create(['external_id' => (string) Str::uuid(), 'title' => 'Quote'])],
    'order' => [EditOrder::class, fn () => Order::create(['external_id' => (string) Str::uuid()])],
    'invoice' => [EditInvoice::class, fn () => Invoice::create(['external_id' => (string) Str::uuid()])],
    'purchase order' => [EditPurchaseOrder::class, fn () => PurchaseOrder::create(['external_id' => (string) Str::uuid()])],
]);

it('links nothing when only one of the two is set', function () {
    $method = new ReflectionMethod(CreateDeal::class, 'handleRecordCreation');
    $method->invoke(new CreateDeal, ['title' => 'Deal', 'currency' => 'USD', 'person_id' => $this->person->id, 'products' => []]);

    expect($this->person->contacts()->count())->toBe(0);
});
