<?php

use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use VentureDrake\LaravelCrmFilament\Concerns\HasCrmCustomFieldEntries;
use VentureDrake\LaravelCrmFilament\RelationManagers\ProductVariationsRelationManager;
use VentureDrake\LaravelCrmFilament\Resources\Products\Pages\ViewProduct;
use VentureDrake\LaravelCrmFilament\Resources\Products\ProductResource;

it('uses the HasCrmCustomFieldEntries trait', function (): void {
    $traits = class_uses_recursive(ProductResource::class);

    expect($traits)->toContain(HasCrmCustomFieldEntries::class);
});

it('declares infolist() locally on ProductResource (not inherited)', function (): void {
    $declaringClass = (new ReflectionMethod(ProductResource::class, 'infolist'))->getDeclaringClass()->getName();

    expect($declaringClass)->toBe(ProductResource::class);
});

it('infolist source contains a Details section heading', function (): void {
    $src = file_get_contents((new ReflectionClass(ProductResource::class))->getFileName());

    expect($src)->toContain("Section::make(__('laravel-crm-filament::labels.sections.details'))");
});

it('infolist source contains a Custom fields section heading', function (): void {
    $src = file_get_contents((new ReflectionClass(ProductResource::class))->getFileName());

    expect($src)->toContain("Section::make(__('laravel-crm-filament::labels.sections.custom_fields'))");
});

it('Details section contains the ten TextEntries in screenshot order', function (): void {
    $src = file_get_contents((new ReflectionClass(ProductResource::class))->getFileName());

    $expectedSequence = [
        "TextEntry::make('code')",
        "->label(__('laravel-crm-filament::labels.money.sku'))",
        "TextEntry::make('barcode')",
        "TextEntry::make('purchase_account')",
        "TextEntry::make('sales_account')",
        "TextEntry::make('unit')",
        "TextEntry::make('taxRate.name')",
        "TextEntry::make('taxRate.rate')",
        "->suffix('%')",
        "TextEntry::make('productCategory.name')",
        "TextEntry::make('description')",
        '->columnSpanFull()',
        "TextEntry::make('ownerUser.name')",
        "->placeholder('Unallocated')",
    ];

    $cursor = 0;
    foreach ($expectedSequence as $needle) {
        $pos = strpos($src, $needle, $cursor);
        expect($pos)->not->toBeFalse("Expected to find '{$needle}' at or after position {$cursor}");
        $cursor = $pos + strlen($needle);
    }
});

it('Details section folds in ungrouped custom field entries via crmCustomFieldEntries(record, false)', function (): void {
    $src = file_get_contents((new ReflectionClass(ProductResource::class))->getFileName());

    expect($src)->toContain('static::crmCustomFieldEntries($record, false)');
});

it('Custom fields section calls crmCustomFieldEntries(record, true)', function (): void {
    $src = file_get_contents((new ReflectionClass(ProductResource::class))->getFileName());

    expect($src)->toContain('static::crmCustomFieldEntries($record, true)');
});

it('Custom fields section is hidden when no FieldValues have a field_group_id', function (): void {
    $src = file_get_contents((new ReflectionClass(ProductResource::class))->getFileName());

    expect($src)->toContain('->hidden(function ($record): bool {');
    expect($src)->toContain("whereHas('field', fn (\$q) => \$q->whereNotNull('field_group_id'))");
});

it('infolist() Schema root has Details + Custom fields top-level Sections', function (): void {
    $page = (new ReflectionClass(ViewProduct::class))->newInstanceWithoutConstructor();
    $schema = Schema::make($page);

    ProductResource::infolist($schema);

    $sections = array_values(array_filter(
        $schema->getComponents(withHidden: true),
        fn ($c) => $c instanceof Section,
    ));

    expect($sections)->toHaveCount(2);
});

it('returns an empty array from getRelations()', function (): void {
    expect(ProductResource::getRelations())->toBe([]);
});

it('no longer imports ProductVariationsRelationManager in ProductResource source', function (): void {
    $src = file_get_contents((new ReflectionClass(ProductResource::class))->getFileName());

    expect($src)->not->toContain('use VentureDrake\LaravelCrmFilament\RelationManagers\ProductVariationsRelationManager;');
    expect($src)->not->toContain('ProductVariationsRelationManager::class');
});

it('mounts ProductVariationsRelationManager on the view page, read-only, not as a resource relation', function (): void {
    $src = file_get_contents((new ReflectionClass(ViewProduct::class))->getFileName());

    expect($src)->toContain('Livewire::make(ProductVariationsRelationManager::class');
    expect((new ReflectionClass(ProductVariationsRelationManager::class))->newInstanceWithoutConstructor()->isReadOnly())->toBeTrue();
});
