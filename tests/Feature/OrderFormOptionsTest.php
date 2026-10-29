<?php

use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Address;
use VentureDrake\LaravelCrm\Models\AddressType;
use VentureDrake\LaravelCrm\Models\Label;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\OrderAddressTabs;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\CreateOrder;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\EditOrder;

use function Pest\Livewire\livewire;

/**
 * Core 2.6.0's order form options (config laravel-crm.orders.form): hide the
 * description, labels and custom fields, take "Unallocated" off the owner
 * select, and choose which address types the form shows. Hiding a field never
 * changes what is stored.
 */
beforeEach(function () {
    $this->user = actingAsCrmAdmin();

    Setting::create(['external_id' => (string) Str::uuid(), 'name' => 'currency', 'value' => 'USD']);

    $this->billingTypeId = AddressType::firstOrCreate(['name' => 'Billing'], ['description' => 'Billing address'])->id;
    $this->shippingTypeId = AddressType::firstOrCreate(['name' => 'Shipping'], ['description' => 'Shipping address'])->id;

    $this->person = Person::create(['external_id' => (string) Str::uuid(), 'first_name' => 'Bob', 'last_name' => 'Vance']);
});

function orderFormSectionChildren(Section $section): array
{
    $ref = new ReflectionProperty($section, 'childComponents');

    return $ref->getValue($section)['default'] ?? [];
}

it('shows the full form when nothing is configured', function () {
    livewire(CreateOrder::class)
        ->assertFormFieldExists('description')
        ->assertFormFieldExists('labels')
        ->assertFormFieldExists('billing_address.line1')
        ->assertFormFieldExists('shipping_address.line1')
        ->assertFormFieldExists('user_owner_id', fn ($field) => ! $field->isRequired());
});

it('hides the description, labels and custom fields', function () {
    config()->set('laravel-crm.orders.form', [
        'description' => false,
        'labels' => false,
        'custom_fields' => false,
    ]);

    livewire(CreateOrder::class)
        ->assertFormFieldDoesNotExist('description')
        ->assertFormFieldDoesNotExist('labels');
});

it('leaves a hidden description and hidden labels as stored on edit', function () {
    $label = Label::create(['external_id' => (string) Str::uuid(), 'name' => 'VIP']);

    $order = Order::create([
        'external_id' => (string) Str::uuid(),
        'person_id' => $this->person->id,
        'description' => 'Keep me',
        'currency' => 'USD',
    ]);
    $order->labels()->sync([$label->id]);

    config()->set('laravel-crm.orders.form', ['description' => false, 'labels' => false]);

    livewire(EditOrder::class, ['record' => $order->external_id])
        ->fillForm(['reference' => 'EDITED'])
        ->call('save')
        ->assertHasNoFormErrors();

    $order->refresh();

    expect($order->reference)->toBe('EDITED')
        ->and($order->description)->toBe('Keep me')
        ->and($order->labels()->pluck('id')->all())->toBe([$label->id]);
});

it('requires an owner and presets the signed-in user when unallocated is off', function () {
    config()->set('laravel-crm.orders.form.unallocated_owner', false);

    livewire(CreateOrder::class)
        ->assertFormFieldExists('user_owner_id', fn ($field) => $field->isRequired())
        ->assertSchemaStateSet(['user_owner_id' => $this->user->id])
        ->fillForm(['person_id' => $this->person->id, 'user_owner_id' => null])
        ->call('create')
        ->assertHasFormErrors(['user_owner_id' => 'required']);
});

it('opens an unowned order on the signed-in user when unallocated is off', function () {
    $order = Order::create(['external_id' => (string) Str::uuid(), 'person_id' => $this->person->id, 'currency' => 'USD']);

    config()->set('laravel-crm.orders.form.unallocated_owner', false);

    livewire(EditOrder::class, ['record' => $order->external_id])
        ->assertSchemaStateSet(['user_owner_id' => $this->user->id]);
});

it('renders a single configured address type as a plain card', function () {
    $section = OrderAddressTabs::make(['shipping']);

    expect($section)->toBeInstanceOf(Section::class)
        ->and($section->getHeading())->toBe('Shipping address')
        ->and(collect(orderFormSectionChildren($section))->contains(fn ($c) => $c instanceof Tabs))->toBeFalse();

    expect(OrderAddressTabs::make([]))->toBeNull();
});

it('only sends the configured address types', function () {
    $addresses = OrderAddressTabs::fromFormData([
        'billing_address' => ['line1' => '1 Bill St'],
        'shipping_address' => ['line1' => '2 Ship St'],
    ], ['shipping']);

    expect($addresses)->toHaveCount(1)
        ->and($addresses[0]['address_type_id'])->toBe((int) $this->shippingTypeId);
});

it('keeps an address of a hidden type on edit', function () {
    $order = Order::create(['external_id' => (string) Str::uuid(), 'person_id' => $this->person->id, 'currency' => 'USD']);

    $billing = Address::create([
        'external_id' => (string) Str::uuid(),
        'addressable_type' => Order::class,
        'addressable_id' => $order->id,
        'address_type_id' => $this->billingTypeId,
        'line1' => '1 Bill St',
    ]);

    config()->set('laravel-crm.orders.form.addresses', ['shipping']);

    livewire(EditOrder::class, ['record' => $order->external_id])
        ->assertFormFieldDoesNotExist('billing_address.line1')
        ->fillForm(['shipping_address' => ['line1' => '2 Ship St']])
        ->call('save')
        ->assertHasNoFormErrors();

    $byType = $order->fresh()->addresses->keyBy('address_type_id');

    expect($byType)->toHaveCount(2)
        ->and($byType[$this->billingTypeId]->id)->toBe($billing->id)
        ->and($byType[$this->shippingTypeId]->line1)->toBe('2 Ship St');
});
