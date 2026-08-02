<?php

use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\LineItemsRepeater;
use VentureDrake\LaravelCrmFilament\Pages\Dashboard;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\CreateInvoice;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\CreateOrder;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\CreateQuote;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

beforeEach(function () {
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'Dynamic Products Tester',
        'email' => 'dynamic-products-' . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->assignRole('Admin');
    $this->actingAs($this->user);

    // Product::getDefaultPrice() resolves the currency through Setting::currency(),
    // which blows up on a missing row (the scope returns the Builder), so every
    // line-item test needs one.
    Setting::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'currency',
        'value' => 'USD',
    ]);
});

function enableDynamicProducts(bool $enabled = true): void
{
    Setting::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'dynamic_products',
        'value' => $enabled ? 1 : 0,
    ]);

    // set()/create() don't bust the settings cache.
    app('laravel-crm.settings')->forgetCache();
}

/**
 * Pull the product Select out of the shared line-items Repeater and give it a
 * container, which getCreateOptionAction() needs to evaluate isDisabled().
 */
function lineItemProductSelect(string $priceField = 'unit_price'): Select
{
    $repeater = LineItemsRepeater::products(
        $priceField === 'unit_price' ? 'quote_product_id' : 'deal_product_id',
        $priceField,
    );

    $ref = new ReflectionProperty($repeater, 'childComponents');
    $ref->setAccessible(true);
    $children = $ref->getValue($repeater);

    $select = collect($children['default'])->first(
        fn ($component) => $component instanceof Select && $component->getName() === 'id'
    );

    $select->container(Schema::make(new Dashboard));

    return $select;
}

it('reports dynamic products as disabled when the setting is absent', function () {
    expect(LineItemsRepeater::dynamicProductsEnabled())->toBeFalse();
});

it('reports dynamic products as disabled when the setting is off', function () {
    enableDynamicProducts(false);

    expect(LineItemsRepeater::dynamicProductsEnabled())->toBeFalse();
});

it('reports dynamic products as enabled when the setting is on', function () {
    enableDynamicProducts();

    expect(LineItemsRepeater::dynamicProductsEnabled())->toBeTrue();
});

it('returns a null create-option schema when dynamic products is off', function () {
    expect(LineItemsRepeater::productCreateForm())->toBeNull();
});

it('returns the product create-option schema when dynamic products is on', function () {
    enableDynamicProducts();

    $fields = LineItemsRepeater::productCreateForm();

    expect($fields)->toBeArray();
    expect(array_map(fn ($field) => $field->getName(), $fields))
        ->toBe(['name', 'code', 'unit_price', 'currency', 'tax_rate_id', 'description']);
});

it('offers no inline product creation on the line-item Select when dynamic products is off', function (string $priceField) {
    $select = lineItemProductSelect($priceField);

    expect($select->hasCreateOptionActionFormSchema())->toBeFalse();
    expect($select->getCreateOptionAction())->toBeNull();
})->with(['unit_price', 'price']);

it('offers inline product creation on the line-item Select when dynamic products is on', function (string $priceField) {
    enableDynamicProducts();

    $select = lineItemProductSelect($priceField);

    expect($select->hasCreateOptionActionFormSchema())->toBeTrue();
    expect($select->getCreateOptionAction()?->getName())->toBe('createOption');
    expect($select->getCreateOptionUsing())->toBeInstanceOf(Closure::class);
})->with(['unit_price', 'price']);

it('labels the inline create modal with the create_product label', function () {
    enableDynamicProducts();

    expect(lineItemProductSelect()->getCreateOptionModalHeading())
        ->toBe(__('laravel-crm-filament::labels.actions.create_product'));
});

it('persists a product from the inline modal payload and returns its key', function () {
    $key = LineItemsRepeater::createProduct([
        'name' => 'Inline Widget',
        'code' => 'IW-1',
        'unit_price' => 42.5,
        'description' => 'Created from a line item',
    ]);

    $product = Product::find($key);

    expect($product)->not->toBeNull();
    expect($product->name)->toBe('Inline Widget');
    expect($product->code)->toBe('IW-1');
    expect($product->description)->toBe('Created from a line item');

    // ProductPrice stores cents, and the default price must be findable through
    // the currency setting or the line item can't read a unit price back.
    expect($product->getDefaultPrice())->not->toBeNull();
    expect((int) $product->getDefaultPrice()->unit_price)->toBe(4250);
    expect($product->getDefaultPrice()->currency)->toBe('USD');
});

it('defaults the inline product owner to the acting user', function () {
    $product = Product::find(LineItemsRepeater::createProduct(['name' => 'Owned Widget']));

    expect($product->user_owner_id)->toBe($this->user->getKey());
});

it('honours an explicit owner and currency on the inline product payload', function () {
    $other = User::create([
        'name' => 'Other Owner',
        'email' => 'other-owner-' . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);

    $product = Product::find(LineItemsRepeater::createProduct([
        'name' => 'Euro Widget',
        'unit_price' => 10,
        'currency' => 'EUR',
        'user_owner_id' => $other->getKey(),
    ]));

    expect($product->user_owner_id)->toBe($other->getKey());
    expect($product->productPrices()->first()->currency)->toBe('EUR');
});

it('creates and selects a product inline on the line items of each sales document', function (string $page) {
    enableDynamicProducts();

    $component = livewire($page)
        ->set('data.products', [
            'row1' => ['id' => null, 'quantity' => 2],
        ])
        ->callAction(
            TestAction::make('createOption')->schemaComponent('products.row1.id'),
            ['name' => 'Inline Widget', 'unit_price' => 25, 'currency' => 'USD'],
        )
        ->assertHasNoActionErrors();

    $product = Product::query()->where('name', 'Inline Widget')->first();
    expect($product)->not->toBeNull();

    // Selected on the row, and the Select's afterStateUpdated pulled the price
    // through so the line totals are already right.
    $component->assertSet('data.products.row1.id', $product->getKey());
    expect((float) $component->get('data.products.row1.unit_price'))->toBe(25.0);
    expect((float) $component->get('data.products.row1.amount'))->toBe(50.0);
})->with([
    CreateQuote::class,
    CreateOrder::class,
    CreateInvoice::class,
]);

it('exposes no inline create action on the line items when dynamic products is off', function (string $page) {
    enableDynamicProducts(false);

    $component = livewire($page)->set('data.products', [
        'row1' => ['id' => null, 'quantity' => 2],
    ]);

    expect(fn () => $component->callAction(
        TestAction::make('createOption')->schemaComponent('products.row1.id'),
        ['name' => 'Should Not Exist'],
    ))->toThrow(ActionNotResolvableException::class);

    expect(Product::query()->where('name', 'Should Not Exist')->exists())->toBeFalse();
})->with([
    CreateQuote::class,
    CreateOrder::class,
    CreateInvoice::class,
]);
