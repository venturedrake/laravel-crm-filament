<?php

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\ProductCategory;
use VentureDrake\LaravelCrm\Models\ProductPrice;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrm\Models\TaxRate;
use VentureDrake\LaravelCrm\Models\XeroItem;
use VentureDrake\LaravelCrmFilament\Resources\Products\Pages\ListProducts;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Setting::query()->firstOrCreate(
        ['name' => 'currency'],
        ['external_id' => (string) Str::uuid(), 'value' => 'USD']
    );
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'Product Columns Tester',
        'email' => 'product-columns-tester' . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->assignRole('Admin');
    $this->actingAs($this->user);
});

/**
 * @return array<string, Column>
 */
function productTableColumns(): array
{
    /** @var ListProducts $instance */
    $instance = livewire(ListProducts::class)->instance();

    $columns = $instance->getTable()->getColumns();

    $out = [];
    foreach ($columns as $col) {
        $out[$col->getName()] = $col;
    }

    return $out;
}

it('renders the 11 plan columns in the prescribed order', function () {
    $names = array_keys(productTableColumns());

    expect($names)->toBe([
        'name',
        'xero_contact_indicator',
        'code',
        'productCategory.name',
        'unit',
        'price',
        'taxRate.name',
        'taxRate.rate',
        'active',
        'ownerUser.name',
        'created_at',
    ]);
});

it('renders xero_contact_indicator as an IconColumn with a hidden header label and a boolean state closure reading xeroItem', function () {
    $cols = productTableColumns();

    expect($cols['xero_contact_indicator'])->toBeInstanceOf(IconColumn::class);
    expect($cols['xero_contact_indicator']->getLabel())->toBe('');

    $source = file_get_contents(__DIR__ . '/../../src/Resources/Products/ProductResource.php');
    expect($source)->toContain("Tables\\Columns\\IconColumn::make('xero_contact_indicator')");
    expect($source)->toContain('->boolean()');
    expect($source)->toContain('$record?->xeroItem !== null');
});

it('renders code and taxRate.name columns as toggleable', function () {
    $cols = productTableColumns();

    expect($cols['code']->isToggleable())->toBeTrue();
    expect($cols['taxRate.name']->isToggleable())->toBeTrue();
});

it('renders the price column with CrmMoney + getDefaultPrice in source and uses price as the column key', function () {
    $cols = productTableColumns();

    expect($cols)->toHaveKey('price');

    $source = file_get_contents(__DIR__ . '/../../src/Resources/Products/ProductResource.php');
    expect($source)->toContain("CrmMoney::column('price'");
    expect($source)->toContain('getDefaultPrice');
});

it('renders taxRate.rate with a percent suffix', function () {
    $source = file_get_contents(__DIR__ . '/../../src/Resources/Products/ProductResource.php');

    expect($source)->toContain("Tables\\Columns\\TextColumn::make('taxRate.rate')");
    expect($source)->toContain("->suffix('%')");
});

it('renders created_at with relative-time formatting and is visible by default (not hidden)', function () {
    $cols = productTableColumns();
    $created = $cols['created_at'];

    expect($created->isToggleable())->toBeTrue();
    expect($created->isToggledHiddenByDefault())->toBeFalse();

    $source = file_get_contents(__DIR__ . '/../../src/Resources/Products/ProductResource.php');
    expect($source)->toContain("Tables\\Columns\\TextColumn::make('created_at')");
    expect($source)->toContain('->since()');
});

it('renders ownerUser.name with the literal Unallocated placeholder', function () {
    $cols = productTableColumns();

    expect($cols['ownerUser.name']->getPlaceholder())->toBe('Unallocated');

    $source = file_get_contents(__DIR__ . '/../../src/Resources/Products/ProductResource.php');
    expect($source)->toContain("Tables\\Columns\\TextColumn::make('ownerUser.name')");
    expect($source)->toContain('labels.misc.unallocated');
});

it('declares the default sort as created_at desc', function () {
    $source = file_get_contents(__DIR__ . '/../../src/Resources/Products/ProductResource.php');

    expect($source)->toContain("->defaultSort('created_at', 'desc')");
});

it('registers exactly two drawer filters: owner (multi+searchable+preload via ownerUser) and labels (multi+preload via labels)', function () {
    /** @var ListProducts $instance */
    $instance = livewire(ListProducts::class)->instance();

    $filters = $instance->getTable()->getFilters();

    expect(array_keys($filters))->toBe(['user_owner_id', 'labels']);

    /** @var SelectFilter $ownerFilter */
    $ownerFilter = $filters['user_owner_id'];
    expect($ownerFilter)->toBeInstanceOf(SelectFilter::class);
    expect($ownerFilter->isMultiple())->toBeTrue();
    expect($ownerFilter->getRelationshipName())->toBe('ownerUser');
    expect($ownerFilter->getSearchable())->toBeTrue();
    expect($ownerFilter->isPreloaded())->toBeTrue();

    /** @var SelectFilter $labelsFilter */
    $labelsFilter = $filters['labels'];
    expect($labelsFilter)->toBeInstanceOf(SelectFilter::class);
    expect($labelsFilter->isMultiple())->toBeTrue();
    expect($labelsFilter->getRelationshipName())->toBe('labels');
    expect($labelsFilter->isPreloaded())->toBeTrue();
});

it('registers View, Edit, and Delete row actions in that order with Delete requiring confirmation', function () {
    /** @var ListProducts $instance */
    $instance = livewire(ListProducts::class)->instance();

    $recordActions = array_values($instance->getTable()->getRecordActions());
    $names = array_map(fn ($a) => $a->getName(), $recordActions);

    expect($names)->toBe(['view', 'edit', 'delete']);
    expect($recordActions[0])->toBeInstanceOf(ViewAction::class);
    expect($recordActions[1])->toBeInstanceOf(EditAction::class);
    expect($recordActions[2])->toBeInstanceOf(DeleteAction::class);
    expect($recordActions[2]->isConfirmationRequired())->toBeTrue();
});

it('eager-loads the 6 named relations via modifyQueryUsing(->with([...]))', function () {
    $source = file_get_contents(__DIR__ . '/../../src/Resources/Products/ProductResource.php');

    expect($source)->toContain('->modifyQueryUsing');
    expect($source)->toContain('->with([');

    foreach ([
        "'productCategory'",
        "'taxRate'",
        "'ownerUser'",
        "'productPrices'",
        "'xeroItem'",
        "'labels'",
    ] as $relation) {
        expect($source)->toContain($relation);
    }
});

it('renders the ListProducts page with a seeded product, productPrice, category, taxRate, and owner', function () {
    $category = ProductCategory::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Tools',
    ]);

    $taxRate = TaxRate::create([
        'name' => 'Standard',
        'rate' => 10,
    ]);

    $product = Product::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Widget Pro',
        'code' => 'WP-001',
        'unit' => 'each',
        'product_category_id' => $category->id,
        'tax_rate_id' => $taxRate->id,
        'user_owner_id' => $this->user->id,
    ]);

    ProductPrice::create([
        'external_id' => (string) Str::uuid(),
        'product_id' => $product->id,
        'unit_price' => 25,
        'currency' => 'USD',
        'default' => true,
    ]);

    livewire(ListProducts::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$product]);
});

it('exposes the fields.unit translation key in en, fr, and es', function () {
    $langRoot = dirname(__DIR__, 2) . '/resources/lang';

    foreach (['en', 'fr', 'es'] as $locale) {
        $labels = require $langRoot . '/' . $locale . '/labels.php';
        expect($labels['fields']['unit'] ?? null)
            ->not->toBeNull()
            ->and($labels['fields']['unit'])->not->toBe('');
    }
});

it('exposes the money.price translation key in en, fr, and es', function () {
    $langRoot = dirname(__DIR__, 2) . '/resources/lang';

    foreach (['en', 'fr', 'es'] as $locale) {
        $labels = require $langRoot . '/' . $locale . '/labels.php';
        expect($labels['money']['price'] ?? null)
            ->not->toBeNull()
            ->and($labels['money']['price'])->not->toBe('');
    }
});

it('renders the xero_contact_indicator true when a linked XeroItem row exists', function () {
    $product = Product::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Linked Product',
    ]);

    XeroItem::create([
        'external_id' => (string) Str::uuid(),
        'product_id' => $product->id,
        'item_id' => 'xero-item-uuid',
        'name' => 'Linked Product',
    ]);

    $cols = productTableColumns();
    $iconCol = $cols['xero_contact_indicator'];

    $ref = new ReflectionProperty(IconColumn::class, 'getStateUsing');
    $ref->setAccessible(true);
    $closure = $ref->getValue($iconCol);

    expect($closure)->toBeInstanceOf(Closure::class);
    expect($closure($product->fresh()))->toBeTrue();
});
