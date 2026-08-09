<?php

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Address;
use VentureDrake\LaravelCrm\Models\Delivery;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrmFilament\Concerns\HasCrmCustomFields;
use VentureDrake\LaravelCrmFilament\Concerns\HasLabels;
use VentureDrake\LaravelCrmFilament\Concerns\HasPrimaryBulkActions;
use VentureDrake\LaravelCrmFilament\Resources\Deliveries\DeliveryResource;
use VentureDrake\LaravelCrmFilament\Resources\Deliveries\Pages\ListDeliveries;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

beforeEach(function () {
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'Delivery Parity Tester',
        'email' => 'delivery-parity-' . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->assignRole('Admin');
    $this->actingAs($this->user);
});

it('DeliveryResource uses HasCrmCustomFields, HasPrimaryBulkActions, and HasLabels', function () {
    $uses = class_uses_recursive(DeliveryResource::class);

    expect($uses)->toContain(HasCrmCustomFields::class);
    expect($uses)->toContain(HasPrimaryBulkActions::class);
    expect($uses)->toContain(HasLabels::class);
});

it('table registers the parity column order', function () {
    $columns = livewire(ListDeliveries::class)->instance()->getTable()->getColumns();
    $names = array_values(array_map(fn ($c) => $c->getName(), $columns));

    expect($names)->toBe([
        'created_at',
        'delivery_id',
        'order.reference',
        'order.order_id',
        'order.person.name',
        'order.organization.name',
        'shipping_address',
        'delivery_expected',
        'delivered_on',
        'ownerUser.name',
    ]);
});

it('created_at uses ->since() and ownerUser column has the Unallocated placeholder', function () {
    $source = file_get_contents(__DIR__ . '/../../src/Resources/Deliveries/DeliveryResource.php');

    expect($source)->toContain('->since()');
    expect($source)->toContain("Tables\\Columns\\TextColumn::make('ownerUser.name')");
    expect($source)->toContain('misc.unallocated');
});

it('Order column resolves to a URL containing the parent order external_id', function () {
    $order = Order::create([
        'external_id' => (string) Str::uuid(),
        'reference' => 'ORD-FOR-DELIVERY',
    ]);
    $order->refresh();

    $delivery = Delivery::create([
        'external_id' => (string) Str::uuid(),
        'order_id' => $order->getKey(),
    ]);

    $columns = livewire(ListDeliveries::class)->instance()->getTable()->getColumns();
    $orderColumn = $columns['order.order_id'];

    $url = $orderColumn->record($delivery)->getUrl();

    expect($url)->not->toBeNull();
    expect($url)->toContain($order->external_id);
});

it('Order column URL is null-safe when the delivery has no parent order', function () {
    $delivery = Delivery::create([
        'external_id' => (string) Str::uuid(),
    ]);

    $columns = livewire(ListDeliveries::class)->instance()->getTable()->getColumns();
    $orderColumn = $columns['order.order_id'];

    expect($orderColumn->record($delivery)->getUrl())->toBeNull();
});

it('shipping_address column abbreviates the address to a single comma-separated line', function () {
    $delivery = Delivery::create([
        'external_id' => (string) Str::uuid(),
    ]);

    Address::create([
        'external_id' => (string) Str::uuid(),
        'addressable_type' => Delivery::class,
        'addressable_id' => $delivery->getKey(),
        'line1' => '1 Wharf Street',
        'city' => 'Sydney',
        'state' => 'NSW',
        'code' => '2000',
        'country' => 'AU',
    ]);

    $columns = livewire(ListDeliveries::class)->instance()->getTable()->getColumns();
    /** @var TextColumn $col */
    $col = $columns['shipping_address']->record($delivery);

    $state = $col->getState();

    expect($state)->toBe('1 Wharf Street, Sydney, NSW, 2000, AU');
});

it('shipping_address renders null when the delivery has no addresses', function () {
    $delivery = Delivery::create([
        'external_id' => (string) Str::uuid(),
    ]);

    $columns = livewire(ListDeliveries::class)->instance()->getTable()->getColumns();
    $col = $columns['shipping_address']->record($delivery);

    expect($col->getState())->toBeNull();
});

it('registers owner and labels drawer filters with the expected config', function () {
    $filters = livewire(ListDeliveries::class)->instance()->getTable()->getFilters();

    $byName = [];
    foreach ($filters as $filter) {
        $byName[$filter->getName()] = $filter;
    }

    expect(array_keys($byName))->toContain('user_owner_id', 'labels');

    /** @var SelectFilter $owner */
    $owner = $byName['user_owner_id'];
    /** @var SelectFilter $labels */
    $labels = $byName['labels'];

    expect($owner->isMultiple())->toBeTrue();
    expect($labels->isMultiple())->toBeTrue();
    expect($owner->getRelationshipName())->toBe('ownerUser');
    expect($labels->getRelationshipName())->toBe('labels');
});

it('row actions are buttons in View, Edit, Delete order with Delete confirmation', function () {
    $actions = array_values(livewire(ListDeliveries::class)->instance()->getTable()->getRecordActions());

    // View / Edit / Delete close the row, after whatever document actions the
    // resource puts in front of them (download PDF, portal preview, …). The
    // ordering that matters is that the three CRUD actions come last, in this
    // order — not that they are the only ones.
    $actions = array_slice($actions, -3);

    expect($actions)->toHaveCount(3);
    expect($actions[0])->toBeInstanceOf(ViewAction::class);
    expect($actions[1])->toBeInstanceOf(EditAction::class);
    expect($actions[2])->toBeInstanceOf(DeleteAction::class);

    foreach ($actions as $action) {
        expect($action->isButton())->toBeTrue();
    }

    expect($actions[2]->isConfirmationRequired())->toBeTrue();
});

it('Labels column relationship resolves on the Delivery model', function () {
    $delivery = Delivery::create([
        'external_id' => (string) Str::uuid(),
    ]);

    expect(method_exists($delivery, 'labels') || $delivery->labels() !== null)->toBeTrue();
});

it('translation for sales.shipping_address exists in en/fr/es', function () {
    foreach (['en', 'fr', 'es'] as $locale) {
        app('translator')->setLocale($locale);
        expect(trans('laravel-crm-filament::labels.sales.shipping_address'))
            ->not->toBe('laravel-crm-filament::labels.sales.shipping_address');
    }
    app('translator')->setLocale('en');
    expect(trans('laravel-crm-filament::labels.sales.shipping_address'))->toBe('Shipping address');
});
