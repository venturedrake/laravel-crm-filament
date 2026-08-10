<?php

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use VentureDrake\LaravelCrmFilament\RelationManagers\ProductPricesRelationManager;

it('declares the relationship as productPrices', function () {
    $reflection = new ReflectionClass(ProductPricesRelationManager::class);
    $property = $reflection->getProperty('relationship');

    expect($property->getValue())->toBe('productPrices');
});

it('extends the Filament RelationManager base class', function () {
    expect(is_subclass_of(
        ProductPricesRelationManager::class,
        RelationManager::class,
    ))->toBeTrue();
});

it('is read-only', function () {
    $instance = (new ReflectionClass(ProductPricesRelationManager::class))->newInstanceWithoutConstructor();

    expect($instance->isReadOnly())->toBeTrue();
});

it('returns the new sections.prices translation from getTitle()', function () {
    $source = file_get_contents(
        (new ReflectionClass(ProductPricesRelationManager::class))->getFileName(),
    );

    expect($source)->toContain("__('laravel-crm-filament::labels.sections.prices')");
});

it('passes empty header / record / toolbar action arrays', function () {
    $source = file_get_contents(
        (new ReflectionClass(ProductPricesRelationManager::class))->getFileName(),
    );

    expect($source)->toContain('->headerActions([])')
        ->and($source)->toContain('->recordActions([])')
        ->and($source)->toContain('->toolbarActions([])');
});

it('defines exactly two columns named unit_price and currency', function () {
    $instance = (new ReflectionClass(ProductPricesRelationManager::class))->newInstanceWithoutConstructor();
    $table = $instance->table(Table::make($instance));

    $names = array_keys($table->getColumns());

    expect($names)->toBe(['unit_price', 'currency']);
});

it('renders unit_price through the shared CrmMoney column factory', function () {
    $source = file_get_contents(
        (new ReflectionClass(ProductPricesRelationManager::class))->getFileName(),
    );

    // Filament's own ->money() never divides (its $divideBy defaults to a
    // falsy 0), so stored cents have to go through CrmMoney, which formats via
    // the package's money() helper. See CrmMoney and MoneyFormattingParityTest.
    expect($source)->toContain("CrmMoney::column('unit_price')")
        ->and($source)->not->toContain('->money(')
        ->and($source)->not->toContain('/ 100');
});

it('labels unit_price via money.unit_price and currency via fields.currency', function () {
    $source = file_get_contents(
        (new ReflectionClass(ProductPricesRelationManager::class))->getFileName(),
    );

    expect($source)->toContain("__('laravel-crm-filament::labels.money.unit_price')")
        ->and($source)->toContain("__('laravel-crm-filament::labels.fields.currency')");
});

it('sets default sort on id asc for stable insertion-order rendering', function () {
    $source = file_get_contents(
        (new ReflectionClass(ProductPricesRelationManager::class))->getFileName(),
    );

    expect($source)->toContain("->defaultSort('id', 'asc')");
});

it('does not surface cost columns or the default boolean as a visible column', function () {
    $instance = (new ReflectionClass(ProductPricesRelationManager::class))->newInstanceWithoutConstructor();
    $table = $instance->table(Table::make($instance));

    $names = array_keys($table->getColumns());

    expect($names)->not->toContain('cost_per_unit')
        ->and($names)->not->toContain('direct_cost')
        ->and($names)->not->toContain('default');
});

it('reads unit_price straight off the column, with no compensating state closure', function () {
    $instance = (new ReflectionClass(ProductPricesRelationManager::class))->newInstanceWithoutConstructor();
    $table = $instance->table(Table::make($instance));

    /** @var TextColumn $column */
    $column = $table->getColumns()['unit_price'];

    // getStateUsing() is the *setter* in Filament v5 (HasCellState); the
    // read-side is the protected $getStateUsing property. Same gotcha pattern
    // locked-in by PersonListColumnsTest (v0.x US-002 of the parity series
    // continuation). The division now lives in the formatter, so there is no
    // state closure left here at all.
    $ref = new ReflectionProperty(TextColumn::class, 'getStateUsing');
    $ref->setAccessible(true);

    expect($ref->getValue($column))->toBeNull();
});

it('formats unit_price cents exactly as the package money() helper does', function () {
    $instance = (new ReflectionClass(ProductPricesRelationManager::class))->newInstanceWithoutConstructor();
    $table = $instance->table(Table::make($instance));

    /** @var TextColumn $column */
    $column = $table->getColumns()['unit_price'];

    expect($column->formatState(4999))->toBe((string) money(4999, 'USD'))
        ->and($column->formatState(null))->toBeNull();
});
