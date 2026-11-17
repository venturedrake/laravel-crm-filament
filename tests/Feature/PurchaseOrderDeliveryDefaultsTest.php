<?php

use Illuminate\Support\Fluent;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Address;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\PurchaseOrder;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrm\Services\PurchaseOrderService;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\PurchaseOrderDeliverySection;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;

use function Pest\Livewire\livewire;

/**
 * Core 2.7.0 delivers a purchase order only to one of the team's own
 * addresses (Settings → Addresses) that has a street or a city, and calls
 * the other type `pickup` (the panel's form said `collect`).
 * PurchaseOrderService drops any other address id, so the form offers only
 * those, requires one for a delivered PO, and starts a new PO on pickup when
 * there is nowhere to deliver to. Mirrors core's
 * PurchaseOrderDeliveryDefaultsTest.
 */
beforeEach(function () {
    actingAsCrmAdmin();

    Setting::updateOrCreate(['name' => 'currency'], ['external_id' => (string) Str::uuid(), 'value' => 'USD']);
    $this->company = Setting::updateOrCreate(['name' => 'team'], ['external_id' => (string) Str::uuid(), 'value' => 'related']);

    $this->supplier = Organization::create(['external_id' => (string) Str::uuid(), 'name' => 'Supplier Co']);
});

function teamAddress(Setting $company, array $attributes): Address
{
    return $company->addresses()->create(['external_id' => (string) Str::uuid()] + $attributes);
}

it('starts a new purchase order on pickup when the team has no delivery address, and saves it', function (bool $countryOnly) {
    if ($countryOnly) {
        // Only a country is not somewhere to deliver to.
        teamAddress($this->company, ['country' => 'Australia', 'line1' => ' ', 'city' => ' ']);
    }

    expect(PurchaseOrderDeliverySection::deliveryAddressOptions())->toBe([]);

    livewire(CreatePurchaseOrder::class)
        ->assertFormSet(['delivery_type' => 'pickup'])
        ->assertFormFieldIsHidden('delivery_address')
        ->fillForm(['currency' => 'USD', 'organization_id' => $this->supplier->id])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(PurchaseOrder::sole())
        ->delivery_type->toBe('pickup')
        ->address->toBeNull()
        // Not shown as Sent before anyone has sent it (core 2.7.0).
        ->and((bool) PurchaseOrder::sole()->sent)->toBeFalse();
})->with(['no addresses' => false, 'country only' => true]);

it('keeps the deliver default and offers a team address with a street or a city', function (string $field) {
    $address = teamAddress($this->company, [$field => 'Delivery destination']);

    livewire(CreatePurchaseOrder::class)
        ->assertFormSet(['delivery_type' => 'deliver'])
        ->assertFormFieldIsVisible('delivery_address');

    expect(array_keys(PurchaseOrderDeliverySection::deliveryAddressOptions()))->toBe([$address->id]);
})->with(['street' => 'line1', 'city' => 'city']);

it('does not offer a country-only team address alongside a deliverable one', function () {
    $street = teamAddress($this->company, ['line1' => '1 Dock Road']);
    teamAddress($this->company, ['country' => 'Australia']);

    expect(array_keys(PurchaseOrderDeliverySection::deliveryAddressOptions()))->toBe([$street->id]);
});

it('refuses a delivered purchase order with no address', function () {
    teamAddress($this->company, ['line1' => '1 Dock Road']);

    livewire(CreatePurchaseOrder::class)
        ->fillForm([
            'currency' => 'USD',
            'organization_id' => $this->supplier->id,
            'delivery_type' => 'deliver',
            'delivery_address' => null,
        ])
        ->call('create')
        ->assertHasFormErrors(['delivery_address' => 'required'])
        ->assertSee(ucfirst(__('laravel-crm::lang.delivery_address_required')));

    expect(PurchaseOrder::count())->toBe(0);
});

it('refuses an address that is not one of the team\'s', function () {
    teamAddress($this->company, ['line1' => '1 Dock Road']);
    $foreign = $this->supplier->addresses()->create([
        'external_id' => (string) Str::uuid(),
        'line1' => 'Supplier warehouse',
    ]);

    livewire(CreatePurchaseOrder::class)
        ->fillForm([
            'currency' => 'USD',
            'organization_id' => $this->supplier->id,
            'delivery_type' => 'deliver',
            'delivery_address' => $foreign->id,
        ])
        ->call('create')
        ->assertHasFormErrors(['delivery_address' => 'in']);

    expect(PurchaseOrder::count())->toBe(0);
});

it('says there is nowhere to deliver to when deliver is chosen with no team address', function () {
    livewire(CreatePurchaseOrder::class)
        ->fillForm([
            'currency' => 'USD',
            'organization_id' => $this->supplier->id,
            'delivery_type' => 'deliver',
        ])
        ->call('create')
        ->assertHasFormErrors(['delivery_address' => 'required'])
        ->assertSee(ucfirst(__('laravel-crm::lang.delivery_address_unavailable')));

    expect(PurchaseOrder::count())->toBe(0);
});

it('delivers to a team address, as its own copy', function () {
    $address = teamAddress($this->company, ['line1' => '1 Dock Road', 'city' => 'Sydney']);

    livewire(CreatePurchaseOrder::class)
        ->fillForm([
            'currency' => 'USD',
            'organization_id' => $this->supplier->id,
            'delivery_type' => 'deliver',
            'delivery_address' => $address->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $copy = PurchaseOrder::sole()->address;

    expect($copy)->not->toBeNull()
        ->and($copy->id)->not->toBe($address->id)
        ->and($copy->line1)->toBe('1 Dock Road');
});

it('starts the edit form on the purchase order\'s own copy and keeps it', function () {
    $address = teamAddress($this->company, ['line1' => '1 Dock Road']);
    $purchaseOrder = app(PurchaseOrderService::class)->create(new Fluent([
        'currency' => 'USD',
        'delivery_type' => 'deliver',
        'delivery_address' => $address->id,
    ]), null, $this->supplier);
    $copy = $purchaseOrder->fresh()->address;

    // The team's address goes; the PO's copy is still offered.
    $address->delete();

    expect(PurchaseOrderDeliverySection::deliveryAddressOptions($purchaseOrder->fresh()))
        ->toHaveKey($copy->id)
        ->toHaveCount(1);

    livewire(EditPurchaseOrder::class, ['record' => $purchaseOrder->external_id])
        ->assertFormSet(['delivery_type' => 'deliver', 'delivery_address' => $copy->id])
        ->fillForm(['reference' => 'Edited'])
        ->call('save')
        ->assertHasNoFormErrors();

    $purchaseOrder = $purchaseOrder->fresh();

    expect($purchaseOrder->reference)->toBe('Edited')
        ->and($purchaseOrder->delivery_type)->toBe('deliver')
        ->and($purchaseOrder->address->id)->toBe($copy->id)
        ->and($purchaseOrder->address->line1)->toBe('1 Dock Road');
});

it('replaces the copy when another team address is picked on edit', function () {
    $first = teamAddress($this->company, ['line1' => '1 Dock Road']);
    $second = teamAddress($this->company, ['line1' => '2 Quay Street']);
    $purchaseOrder = app(PurchaseOrderService::class)->create(new Fluent([
        'currency' => 'USD',
        'delivery_type' => 'deliver',
        'delivery_address' => $first->id,
    ]), null, $this->supplier);

    livewire(EditPurchaseOrder::class, ['record' => $purchaseOrder->external_id])
        ->fillForm(['delivery_address' => $second->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($purchaseOrder->fresh()->address->line1)->toBe('2 Quay Street');
});

it('keeps an existing pickup as pickup, and reads the panel\'s old `collect` as pickup', function (string $stored) {
    teamAddress($this->company, ['city' => 'Sydney']);
    $purchaseOrder = PurchaseOrder::create([
        'external_id' => (string) Str::uuid(),
        'currency' => 'USD',
        'delivery_type' => $stored,
        'organization_id' => $this->supplier->id,
    ]);

    livewire(EditPurchaseOrder::class, ['record' => $purchaseOrder->external_id])
        ->assertFormSet(['delivery_type' => 'pickup'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($purchaseOrder->fresh()->delivery_type)->toBe('pickup')
        ->and(PurchaseOrderDeliverySection::typeLabel($stored))->toBe(__('laravel-crm-filament::labels.sales.pickup'));
})->with(['pickup' => 'pickup', 'collect' => 'collect']);

it('does not preselect a copy that cannot be delivered to', function () {
    $purchaseOrder = PurchaseOrder::create([
        'external_id' => (string) Str::uuid(),
        'currency' => 'USD',
        'delivery_type' => 'deliver',
        'organization_id' => $this->supplier->id,
    ]);
    $copy = $purchaseOrder->address()->create(['external_id' => (string) Str::uuid(), 'country' => 'Australia']);

    livewire(EditPurchaseOrder::class, ['record' => $purchaseOrder->external_id])
        ->assertFormSet(['delivery_type' => 'deliver', 'delivery_address' => null])
        ->fillForm(['reference' => 'Must not save'])
        ->call('save')
        ->assertHasFormErrors(['delivery_address' => 'required'])
        ->fillForm(['delivery_address' => $copy->id])
        ->call('save')
        ->assertHasFormErrors(['delivery_address' => 'in']);

    expect($purchaseOrder->fresh()->reference)->toBeNull();
});
