<?php

use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\ProductPrice;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\RelationManagers\ProductPricesRelationManager;
use VentureDrake\LaravelCrmFilament\Resources\Products\Pages\ViewProduct;
use VentureDrake\LaravelCrmFilament\Resources\Products\ProductResource;
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
        'name' => 'Product Infolist Tester',
        'email' => 'product-infolist-tester' . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->assignRole('Admin');
    $this->actingAs($this->user);
});

// (a) ProductResource::infolist() declared locally on the Resource, not inherited
// from Filament\Resources\Resource.
it('declares infolist() locally on ProductResource (not inherited)', function (): void {
    $declaringClass = (new ReflectionMethod(ProductResource::class, 'infolist'))->getDeclaringClass()->getName();

    expect($declaringClass)->toBe(ProductResource::class);
});

// (b) Source-grep that sections.details and sections.custom_fields headings appear
// in that order.
it('infolist source contains Details + Custom fields section headings in order', function (): void {
    $src = file_get_contents((new ReflectionClass(ProductResource::class))->getFileName());

    $detailsPos = strpos($src, "Section::make(__('laravel-crm-filament::labels.sections.details'))");
    $customPos = strpos($src, "Section::make(__('laravel-crm-filament::labels.sections.custom_fields'))");

    expect($detailsPos)->not->toBeFalse();
    expect($customPos)->not->toBeFalse();
    expect($detailsPos)->toBeLessThan($customPos);
});

// (c) Source contains both crmCustomFieldEntries calls (ungrouped fold-in +
// grouped body).
it('infolist source folds in ungrouped + grouped custom field entries', function (): void {
    $src = file_get_contents((new ReflectionClass(ProductResource::class))->getFileName());

    expect($src)->toContain('static::crmCustomFieldEntries($record, false)');
    expect($src)->toContain('static::crmCustomFieldEntries($record, true)');
});

// (d) getRelations() returns [] AND source does not reference
// ProductVariationsRelationManager::class.
it('returns an empty getRelations() and no longer references ProductVariationsRelationManager', function (): void {
    expect(ProductResource::getRelations())->toBe([]);

    $src = file_get_contents((new ReflectionClass(ProductResource::class))->getFileName());
    expect($src)->not->toContain('ProductVariationsRelationManager::class');
});

// (e) ViewProduct::content() declared locally, source contains Grid::make + Livewire::make
// strings AND does NOT contain getRelationManagersContentComponent().
it('declares content() locally on ViewProduct with Grid::make + Livewire::make and no relation-managers component', function (): void {
    $declaringClass = (new ReflectionMethod(ViewProduct::class, 'content'))->getDeclaringClass()->getName();
    expect($declaringClass)->toBe(ViewProduct::class);

    $src = file_get_contents((new ReflectionClass(ViewProduct::class))->getFileName());
    expect($src)->toContain("Grid::make(['default' => 1, 'lg' => 2])");
    expect($src)->toContain('Livewire::make(ProductPricesRelationManager::class');
    expect($src)->not->toContain('getRelationManagersContentComponent()');
});

// (f) Schema introspection — content() root is a Grid instance with 2 children
// each carrying columnSpan(['lg' => 1]).
it('content() Schema root is a Grid with two children each spanning lg-1', function (): void {
    $page = (new ReflectionClass(ViewProduct::class))->newInstanceWithoutConstructor();
    $page->record = new Product;

    $schema = Schema::make($page);
    $page->content($schema);

    $components = $schema->getComponents(withHidden: true);
    expect($components)->toHaveCount(1);
    expect($components[0])->toBeInstanceOf(Grid::class);

    $ref = new ReflectionProperty(Grid::class, 'childComponents');
    $ref->setAccessible(true);
    $children = $ref->getValue($components[0]);
    $childList = $children['default'] ?? $children;

    expect($childList)->toHaveCount(2);
    expect($childList[0]->getColumnSpan())->toBe(['lg' => 1]);
    expect($childList[1])->toBeInstanceOf(Livewire::class);
    expect($childList[1]->getColumnSpan())->toBe(['lg' => 1]);
});

// (g) ProductPricesRelationManager: relationship='productPrices', isReadOnly()=true,
// three empty action arrays in source, columns exactly ['unit_price', 'currency'].
it('ProductPricesRelationManager exposes the AC-required contract', function (): void {
    $reflection = new ReflectionClass(ProductPricesRelationManager::class);
    $relationship = $reflection->getProperty('relationship')->getValue();
    expect($relationship)->toBe('productPrices');

    $instance = $reflection->newInstanceWithoutConstructor();
    expect($instance->isReadOnly())->toBeTrue();

    $src = file_get_contents($reflection->getFileName());
    expect($src)->toContain('->headerActions([])');
    expect($src)->toContain('->recordActions([])');
    expect($src)->toContain('->toolbarActions([])');

    $table = $instance->table(Table::make($instance));
    expect(array_keys($table->getColumns()))->toBe(['unit_price', 'currency']);
});

// (h) End-to-end: seed Product with two ProductPrices (USD $25 + EUR €20), mount the
// RelationManager via livewire() and assertCanSeeTableRecords on both prices. Also
// asserts the unit_price state closure divides stored cents back to dollars.
it('renders both seeded ProductPrices on the RelationManager and divides cents to dollars', function (): void {
    $product = Product::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Widget Pro Infolist',
    ]);

    // ProductPrice::setUnitPriceAttribute multiplies by 100 on save, so passing
    // 25 stores 2500 cents on the row.
    $priceUsd = ProductPrice::create([
        'external_id' => (string) Str::uuid(),
        'product_id' => $product->id,
        'unit_price' => 25,
        'currency' => 'USD',
        'default' => true,
    ]);

    $priceEur = ProductPrice::create([
        'external_id' => (string) Str::uuid(),
        'product_id' => $product->id,
        'unit_price' => 20,
        'currency' => 'EUR',
        'default' => false,
    ]);

    livewire(ProductPricesRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => ViewProduct::class,
    ])->assertCanSeeTableRecords([$priceUsd, $priceEur]);

    // Locks the AC's "cents render back as an amount" contract, now that the
    // /100 lives in the formatter rather than a state closure: each row picks
    // up its own currency and formats through the package's money() helper.
    livewire(ProductPricesRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => ViewProduct::class,
    ])
        ->assertSee((string) money($priceUsd->fresh()->unit_price, 'USD'))
        ->assertSee((string) money($priceEur->fresh()->unit_price, 'EUR'));
});
