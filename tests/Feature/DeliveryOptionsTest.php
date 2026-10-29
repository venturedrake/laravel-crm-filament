<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Delivery;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrmFilament\Resources\Deliveries\DeliveryResource;
use VentureDrake\LaravelCrmFilament\Resources\Deliveries\Pages\EditDelivery;
use VentureDrake\LaravelCrmFilament\Resources\Deliveries\Pages\ListDeliveries;
use VentureDrake\LaravelCrmFilament\Resources\Deliveries\Pages\ViewDelivery;

use function Pest\Livewire\livewire;

/**
 * Core 2.6.0's delivery options (config laravel-crm.deliveries): switch off
 * "Delivered on", and pick the label on the expected delivery date.
 */
beforeEach(function () {
    actingAsCrmAdmin();

    $this->order = Order::create(['external_id' => (string) Str::uuid()]);
    $this->delivery = Delivery::create([
        'external_id' => (string) Str::uuid(),
        'order_id' => $this->order->id,
        'delivered_on' => '2026-10-01',
    ]);
});

it('shows "Delivered on" by default', function () {
    livewire(EditDelivery::class, ['record' => $this->delivery->external_id])
        ->assertFormFieldExists('delivered_on')
        ->assertFormFieldExists('delivery_expected', fn ($field) => $field->getLabel() === 'Expected');

    livewire(ListDeliveries::class)->assertTableColumnVisible('delivered_on');
});

it('hides "Delivered on" from the form, table, view page and badge', function () {
    config()->set('laravel-crm.deliveries.delivered_on', false);

    livewire(EditDelivery::class, ['record' => $this->delivery->external_id])
        ->assertFormFieldDoesNotExist('delivered_on');

    livewire(ListDeliveries::class)->assertTableColumnHidden('delivered_on');

    livewire(ViewDelivery::class, ['record' => $this->delivery->external_id])
        ->assertSchemaComponentHidden('delivered_on', 'infolist');

    expect(DeliveryResource::getNavigationBadge())->toBeNull();
});

it('leaves a stored "Delivered on" alone when the field is hidden', function () {
    config()->set('laravel-crm.deliveries.delivered_on', false);

    livewire(EditDelivery::class, ['record' => $this->delivery->external_id])
        ->fillForm(['delivery_expected' => '2026-11-05'])
        ->call('save')
        ->assertHasNoFormErrors();

    $delivery = $this->delivery->fresh();

    expect($delivery->delivery_expected->toDateString())->toBe('2026-11-05')
        ->and($delivery->delivered_on->toDateString())->toBe('2026-10-01');
});

it('labels the expected date with the configured core lang key', function () {
    config()->set('laravel-crm.deliveries.delivery_expected_label', 'delivery_date');

    expect(DeliveryResource::expectedLabel())->toBe('Delivery date');

    livewire(EditDelivery::class, ['record' => $this->delivery->external_id])
        ->assertFormFieldExists('delivery_expected', fn ($field) => $field->getLabel() === 'Delivery date');
});

it('falls back to the panel label for an unknown lang key', function () {
    config()->set('laravel-crm.deliveries.delivery_expected_label', 'no_such_key');

    expect(DeliveryResource::expectedLabel())->toBe('Expected');
});
