<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\ListInvoices;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\ViewInvoice;
use VentureDrake\LaravelCrmFilament\Resources\Orders\OrderResource;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\OrganizationResource;
use VentureDrake\LaravelCrmFilament\Resources\People\PersonResource;

use function Pest\Livewire\livewire;

/**
 * Core 2.7.0: an invoice whose order or contact was soft deleted still names
 * it, marked "(deleted)", with no link to a page that would 404.
 */
beforeEach(function () {
    actingAsCrmAdmin();

    $this->order = Order::create(['external_id' => (string) Str::uuid(), 'currency' => 'USD']);
    $this->person = Person::create(['external_id' => (string) Str::uuid(), 'first_name' => 'Jane', 'last_name' => 'Doe']);
    $this->organization = Organization::create(['external_id' => (string) Str::uuid(), 'name' => 'Acme Pty Ltd']);

    $this->invoice = Invoice::create([
        'external_id' => (string) Str::uuid(),
        'currency' => 'USD',
        'order_id' => $this->order->id,
        'person_id' => $this->person->id,
        'organization_id' => $this->organization->id,
    ]);

    $this->deleted = __('laravel-crm::lang.deleted');
});

it('links a live order, person and organization', function () {
    $orderNumber = $this->order->fresh()->order_id;

    livewire(ViewInvoice::class, ['record' => $this->invoice->external_id])
        ->assertSee($orderNumber)
        ->assertDontSee($orderNumber . ' (' . $this->deleted . ')')
        ->assertSee(OrderResource::getUrl('view', ['record' => $this->order]), false)
        ->assertSee(PersonResource::getUrl('view', ['record' => $this->person]), false)
        ->assertSee(OrganizationResource::getUrl('view', ['record' => $this->organization]), false);
});

it('marks a deleted order as deleted, without a link, on the list and the invoice page', function () {
    $orderNumber = $this->order->fresh()->order_id;
    $orderUrl = OrderResource::getUrl('view', ['record' => $this->order]);

    $this->order->delete();

    $column = livewire(ListInvoices::class)->instance()->getTable()->getColumns()['order.order_id'];
    $column->record($this->invoice->fresh());

    expect($column->getUrl())->toBeNull()
        ->and($column->formatState($column->getState()))->toBe($orderNumber . ' (' . $this->deleted . ')');

    livewire(ViewInvoice::class, ['record' => $this->invoice->external_id])
        ->assertSee($orderNumber . ' (' . $this->deleted . ')')
        ->assertDontSee($orderUrl, false);
});

it('names a deleted person and organization, marked deleted and without a link', function () {
    $personUrl = PersonResource::getUrl('view', ['record' => $this->person]);
    $organizationUrl = OrganizationResource::getUrl('view', ['record' => $this->organization]);

    $this->person->delete();
    $this->organization->delete();

    livewire(ViewInvoice::class, ['record' => $this->invoice->external_id])
        ->assertSee('Jane Doe (' . $this->deleted . ')')
        ->assertSee('Acme Pty Ltd (' . $this->deleted . ')')
        ->assertDontSee($personUrl, false)
        ->assertDontSee($organizationUrl, false);
});
