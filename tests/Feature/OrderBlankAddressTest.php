<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Address;
use VentureDrake\LaravelCrm\Models\AddressType;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\OrderAddressTabs;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\CreateOrder;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\EditOrder;

use function Pest\Livewire\livewire;

/**
 * Core 2.7.0 (#115): an address with nothing typed but a country is blank
 * (AddressContent::isBlank()). It is not saved, and an address cleared down
 * to its country on edit is removed.
 */
beforeEach(function () {
    actingAsCrmAdmin();

    Setting::create(['external_id' => (string) Str::uuid(), 'name' => 'currency', 'value' => 'USD']);

    $this->billingTypeId = (int) AddressType::firstOrCreate(['name' => 'Billing'], ['description' => 'Billing address'])->id;
    $this->shippingTypeId = (int) AddressType::firstOrCreate(['name' => 'Shipping'], ['description' => 'Shipping address'])->id;

    $this->person = Person::create(['external_id' => (string) Str::uuid(), 'first_name' => 'Bob', 'last_name' => 'Vance']);
});

it('does not send a country-only address to the service', function () {
    $addresses = OrderAddressTabs::fromFormData([
        'billing_address' => ['country' => 'Australia'],
        'shipping_address' => ['line1' => '2 Ship St', 'country' => 'Australia'],
    ]);

    expect($addresses)->toHaveCount(1)
        ->and($addresses[0]['address_type_id'])->toBe($this->shippingTypeId);
});

it('does not save a country-only address on create', function () {
    livewire(CreateOrder::class)
        ->fillForm([
            'reference' => 'ORD-BLANK-ADDRESS',
            'currency' => 'USD',
            'person_id' => $this->person->id,
            'billing_address' => ['country' => 'Australia'],
            'shipping_address' => ['line1' => '2 Ship St', 'country' => 'Australia'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $order = Order::where('reference', 'ORD-BLANK-ADDRESS')->firstOrFail();

    expect($order->addresses)->toHaveCount(1)
        ->and((int) $order->addresses->first()->address_type_id)->toBe($this->shippingTypeId);
});

it('removes an address cleared down to its country on edit', function () {
    $order = Order::create(['external_id' => (string) Str::uuid(), 'person_id' => $this->person->id, 'currency' => 'USD']);

    $billing = Address::create([
        'external_id' => (string) Str::uuid(),
        'addressable_type' => Order::class,
        'addressable_id' => $order->id,
        'address_type_id' => $this->billingTypeId,
        'line1' => '1 Bill St',
        'city' => 'Scranton',
        'country' => 'United States',
    ]);

    livewire(EditOrder::class, ['record' => $order->external_id])
        ->fillForm(['billing_address' => [
            'id' => $billing->id,
            'line1' => '',
            'city' => '',
            'country' => 'United States',
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($order->fresh()->addresses)->toHaveCount(0)
        ->and(Address::find($billing->id))->toBeNull();
});
