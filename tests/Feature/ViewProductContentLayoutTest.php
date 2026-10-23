<?php

use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;
use VentureDrake\LaravelCrmFilament\RelationManagers\ProductVariationsRelationManager;
use VentureDrake\LaravelCrmFilament\Resources\Products\Pages\ViewProduct;
use VentureDrake\LaravelCrmFilament\Resources\Products\ProductResource;

it('declares content() locally on ViewProduct (not inherited)', function (): void {
    $declaringClass = (new ReflectionMethod(ViewProduct::class, 'content'))->getDeclaringClass()->getName();

    expect($declaringClass)->toBe(ViewProduct::class);
});

it('content() is declared public and returns Schema', function (): void {
    $method = new ReflectionMethod(ViewProduct::class, 'content');

    expect($method->isPublic())->toBeTrue();
    expect((string) $method->getReturnType())->toBe(Schema::class);
});

it('source contains the AC-required Grid::make shape', function (): void {
    $src = file_get_contents((new ReflectionClass(ViewProduct::class))->getFileName());

    expect($src)->toContain("Grid::make(['default' => 1, 'lg' => 2])");
});

it('source contains Livewire::make(ProductPricesRelationManager::class', function (): void {
    $src = file_get_contents((new ReflectionClass(ViewProduct::class))->getFileName());

    expect($src)->toContain('Livewire::make(ProductPricesRelationManager::class');
});

it('source does NOT call getRelationManagersContentComponent()', function (): void {
    $src = file_get_contents((new ReflectionClass(ViewProduct::class))->getFileName());

    expect($src)->not->toContain('getRelationManagersContentComponent()');
});

it('source includes the four AC-required use imports', function (): void {
    $src = file_get_contents((new ReflectionClass(ViewProduct::class))->getFileName());

    expect($src)->toContain('use Filament\Schemas\Components\Grid;');
    expect($src)->toContain('use Filament\Schemas\Schema;');
    expect($src)->toContain('use Filament\Schemas\Components\Livewire;');
    expect($src)->toContain('use VentureDrake\LaravelCrmFilament\RelationManagers\ProductPricesRelationManager;');
});

it('source builds $ownerData with ownerRecord and pageClass keys', function (): void {
    $src = file_get_contents((new ReflectionClass(ViewProduct::class))->getFileName());

    expect($src)->toContain("'ownerRecord' => \$this->getRecord()");
    expect($src)->toContain("'pageClass' => static::class");
});

it('source pins the Livewire key to product-prices-{record-id}', function (): void {
    $src = file_get_contents((new ReflectionClass(ViewProduct::class))->getFileName());

    expect($src)->toContain("->key('product-prices-' . \$this->getRecord()->getKey())");
});

it('content() Schema root contains a Grid with two columnSpan lg-1 children, then the variations', function (): void {
    $modelClass = ProductResource::getModel();
    $instance = (new ReflectionClass(ViewProduct::class))->newInstanceWithoutConstructor();
    $instance->record = new $modelClass;
    $schema = Schema::make($instance);
    $instance->content($schema);

    $components = $schema->getComponents(withHidden: true);

    expect($components)->toHaveCount(2);
    expect($components[0])->toBeInstanceOf(Grid::class);
    expect($components[0]->getColumns())->toBe(['default' => 1, 'lg' => 2]);

    $ref = new ReflectionProperty(Grid::class, 'childComponents');
    $ref->setAccessible(true);
    $children = $ref->getValue($components[0]);
    $childList = $children['default'] ?? $children;

    expect($childList)->toHaveCount(2);
    expect($childList[0]->getColumnSpan())->toBe(['lg' => 1]);
    expect($childList[1])->toBeInstanceOf(Livewire::class);
    expect($childList[1]->getColumnSpan())->toBe(['lg' => 1]);

    expect($components[1])->toBeInstanceOf(Livewire::class);
    expect($components[1]->getComponent())->toBe(ProductVariationsRelationManager::class);
});
