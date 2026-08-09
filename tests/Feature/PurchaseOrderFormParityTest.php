<?php

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\PurchaseOrder;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\LineItemsRepeater;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\PurchaseOrderDeliverySection;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

beforeEach(function () {
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'PO Form Parity Tester',
        'email' => 'po-form-parity-' . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->assignRole('Admin');
    $this->actingAs($this->user);

    Setting::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'currency',
        'value' => 'USD',
    ]);
});

function poFormChildrenOf(Section $section): array
{
    $ref = new ReflectionProperty($section, 'childComponents');
    $ref->setAccessible(true);

    return $ref->getValue($section)['default'] ?? [];
}

it('PurchaseOrderResource source uses the shared form concerns + delivery section', function () {
    $source = file_get_contents(__DIR__ . '/../../src/Resources/PurchaseOrders/PurchaseOrderResource.php');
    expect($source)->toContain("Grid::make(['default' => 1, 'lg' => 2])");
    expect($source)->toContain('SalesDetailsSection::make([');
    expect($source)->toContain('PurchaseOrderDeliverySection::make()');
    expect($source)->toContain("LineItemsRepeater::products(\n                            fkColumn: 'purchase_order_line_id',");
    expect($source)->toContain('MoneyTotalsRow::make()');
    expect($source)->toContain("'orderLink' => true");
    expect($source)->toContain("'expiryDateKey' => 'delivery_date'");
});

it('PurchaseOrderDeliverySection renders delivery_type + delivery_address + delivery_instructions', function () {
    $section = PurchaseOrderDeliverySection::make();
    expect($section)->toBeInstanceOf(Section::class);

    $children = poFormChildrenOf($section);
    $grid = $children[0];

    $gridRef = new ReflectionProperty($grid, 'childComponents');
    $gridRef->setAccessible(true);
    $gridChildren = $gridRef->getValue($grid)['default'] ?? [];

    $names = array_map(fn ($c) => $c->getName(), $gridChildren);
    expect($names)->toBe([
        'delivery_type',
        'delivery_address',
        'delivery_instructions',
    ]);

    /** @var Select $deliveryType */
    $deliveryType = $gridChildren[0];
    expect($deliveryType)->toBeInstanceOf(Select::class);
});

it('LineItemsRepeater for PurchaseOrder uses purchase_order_line_id + unit_price', function () {
    /** @var Repeater $repeater */
    $repeater = LineItemsRepeater::products('purchase_order_line_id', 'unit_price');

    // Walk one level into the nested row-2 Grid; unit_price variant has tax_amount.
    $names = poFormCollectLeafNames($repeater);

    expect($names)->toBe([
        'purchase_order_line_id',
        'id',
        'unit_price',
        'quantity',
        'tax_amount',
        'amount',
        'comments',
    ]);
});

it('CreatePurchaseOrder persists PurchaseOrder + discount + adjustment columns', function () {
    $person = Person::create([
        'external_id' => (string) Str::uuid(),
        'first_name' => 'Hank',
        'last_name' => 'Schrader',
    ]);
    $organization = Organization::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Madrigal',
    ]);
    $order = Order::create([
        'external_id' => (string) Str::uuid(),
        'reference' => 'ORD-1',
    ]);

    livewire(CreatePurchaseOrder::class)
        ->fillForm([
            'reference' => 'PO-PARITY',
            'currency' => 'USD',
            'order_id' => $order->getKey(),
            'person_id' => $person->getKey(),
            'organization_id' => $organization->getKey(),
            'delivery_type' => 'collect',
            'sub_total' => 200,
            'discount' => 10,
            'tax' => 19,
            'adjustment' => 1,
            'total' => 210,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $po = PurchaseOrder::query()->where('reference', 'PO-PARITY')->first();
    expect($po)->not->toBeNull();
    expect($po->getAttributes()['subtotal'])->toBe(200 * 100);
    expect($po->getAttributes()['discount'])->toBe(10 * 100);
    expect($po->getAttributes()['tax'])->toBe(19 * 100);
    expect($po->getAttributes()['adjustments'])->toBe(1 * 100);
    expect($po->delivery_type)->toBe('collect');
});

it('delivery_details + deliver/collect/delivery_address/instructions translation keys exist in en/fr/es', function () {
    foreach (['en', 'fr', 'es'] as $locale) {
        app('translator')->setLocale($locale);
        foreach (['sections.delivery_details', 'sales.deliver', 'sales.collect', 'sales.delivery_address', 'sales.delivery_instructions'] as $key) {
            expect(trans('laravel-crm-filament::labels.' . $key))->not->toBe('laravel-crm-filament::labels.' . $key);
        }
    }
    app('translator')->setLocale('en');
    expect(trans('laravel-crm-filament::labels.sections.delivery_details'))->toBe('Delivery details');
});

function poFormCollectLeafNames(Repeater | Section | Grid $component): array
{
    $ref = new ReflectionProperty($component, 'childComponents');
    $ref->setAccessible(true);
    $group = $ref->getValue($component);
    $children = $group['default'] ?? $group;

    $names = [];
    foreach ($children as $child) {
        if ($child instanceof Grid) {
            $gref = new ReflectionProperty($child, 'childComponents');
            $gref->setAccessible(true);
            $ggroup = $gref->getValue($child);
            foreach (($ggroup['default'] ?? $ggroup) as $inner) {
                $names[] = $inner->getName();
            }
        } else {
            $names[] = $child->getName();
        }
    }

    return $names;
}
