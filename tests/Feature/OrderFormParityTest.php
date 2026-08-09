<?php

use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Address;
use VentureDrake\LaravelCrm\Models\AddressType;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\OrderAddressTabs;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\CreateOrder;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

beforeEach(function () {
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'Order Form Parity Tester',
        'email' => 'order-form-parity-' . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->assignRole('Admin');
    $this->actingAs($this->user);

    Setting::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'currency',
        'value' => 'USD',
    ]);

    AddressType::firstOrCreate(['name' => 'Billing'], ['description' => 'Billing address']);
    AddressType::firstOrCreate(['name' => 'Shipping'], ['description' => 'Shipping address']);
});

it('OrderResource source uses the shared form concerns and the 2-col Grid + Address tabs', function () {
    $source = file_get_contents(__DIR__ . '/../../src/Resources/Orders/OrderResource.php');
    expect($source)->toContain("Grid::make(['default' => 1, 'lg' => 2])");
    expect($source)->toContain('SalesDetailsSection::make([');
    expect($source)->toContain('OrderAddressTabs::make()');
    expect($source)->toContain("LineItemsRepeater::products(\n                            fkColumn: 'order_product_id',");
    expect($source)->toContain('MoneyTotalsRow::make()');
});

it('OrderAddressTabs::make renders a Section containing a 2-tab Tabs component', function () {
    $section = OrderAddressTabs::make();
    expect($section)->toBeInstanceOf(Section::class);

    $sectionChildren = (function () use ($section) {
        $ref = new ReflectionProperty($section, 'childComponents');
        $ref->setAccessible(true);

        return $ref->getValue($section)['default'] ?? [];
    })();

    expect($sectionChildren)->toHaveCount(1);
    $tabs = $sectionChildren[0];
    expect($tabs)->toBeInstanceOf(Tabs::class);

    $tabsRef = new ReflectionProperty($tabs, 'childComponents');
    $tabsRef->setAccessible(true);
    $tabArray = $tabsRef->getValue($tabs)['default'] ?? [];

    expect($tabArray)->toHaveCount(2);
});

it('OrderAddressTabs::fromFormData omits empty tabs and tags non-empty tabs with address_type_id', function () {
    $billingId = AddressType::query()->where('name', 'Billing')->value('id');
    $shippingId = AddressType::query()->where('name', 'Shipping')->value('id');

    $addresses = OrderAddressTabs::fromFormData([
        'billing_address' => ['line1' => '1 Wharf Street', 'city' => 'Sydney'],
        'shipping_address' => ['line1' => '', 'city' => ''],
    ]);

    expect($addresses)->toHaveCount(1);
    expect($addresses[0]['address_type_id'])->toBe((int) $billingId);
    expect($addresses[0]['line1'])->toBe('1 Wharf Street');

    $bothFilled = OrderAddressTabs::fromFormData([
        'billing_address' => ['line1' => 'A'],
        'shipping_address' => ['line1' => 'B'],
    ]);
    expect($bothFilled)->toHaveCount(2);
    expect($bothFilled[1]['address_type_id'])->toBe((int) $shippingId);
});

it('OrderAddressTabs::toFormData splits addresses into billing + shipping by AddressType', function () {
    $billingId = AddressType::query()->where('name', 'Billing')->value('id');
    $shippingId = AddressType::query()->where('name', 'Shipping')->value('id');

    $billing = new Address(['line1' => 'B1', 'address_type_id' => $billingId]);
    $shipping = new Address(['line1' => 'S1', 'address_type_id' => $shippingId]);

    $data = OrderAddressTabs::toFormData(new Collection([$billing, $shipping]));

    expect($data['billing_address']['line1'])->toBe('B1');
    expect($data['shipping_address']['line1'])->toBe('S1');
});

it('CreateOrder persists Order + addresses through the tabbed shape', function () {
    $person = Person::create([
        'external_id' => (string) Str::uuid(),
        'first_name' => 'Bob',
        'last_name' => 'Vance',
    ]);
    $organization = Organization::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Vance Refrigeration',
    ]);

    livewire(CreateOrder::class)
        ->fillForm([
            'reference' => 'ORD-PARITY',
            'currency' => 'USD',
            'person_id' => $person->getKey(),
            'organization_id' => $organization->getKey(),
            'sub_total' => 100,
            'tax' => 10,
            'total' => 110,
            'billing_address' => ['line1' => '1 Bill St', 'city' => 'Scranton'],
            'shipping_address' => ['line1' => '2 Ship St', 'city' => 'Scranton'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $order = Order::query()->where('reference', 'ORD-PARITY')->first();
    expect($order)->not->toBeNull();
    expect($order->person_id)->toBe($person->getKey());
    expect($order->organization_id)->toBe($organization->getKey());
    expect($order->addresses()->count())->toBe(2);

    $byType = $order->addresses->keyBy(fn ($a) => (int) $a->address_type_id);
    $billingId = (int) AddressType::query()->where('name', 'Billing')->value('id');
    $shippingId = (int) AddressType::query()->where('name', 'Shipping')->value('id');

    expect($byType[$billingId]->line1)->toBe('1 Bill St');
    expect($byType[$shippingId]->line1)->toBe('2 Ship St');
});

it('billing and shipping translation keys exist in en/fr/es', function () {
    foreach (['en', 'fr', 'es'] as $locale) {
        app('translator')->setLocale($locale);
        expect(trans('laravel-crm-filament::labels.contact.billing'))->not->toBe('laravel-crm-filament::labels.contact.billing');
        expect(trans('laravel-crm-filament::labels.contact.shipping'))->not->toBe('laravel-crm-filament::labels.contact.shipping');
    }
    app('translator')->setLocale('en');
    expect(trans('laravel-crm-filament::labels.contact.billing'))->toBe('Billing');
    expect(trans('laravel-crm-filament::labels.contact.shipping'))->toBe('Shipping');
});
