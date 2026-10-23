<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\ProductPrice;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrm\Models\TaxRate;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\LineItemsRepeater;

/**
 * Extracts the Product Select component's afterStateUpdated closure from a
 * LineItemsRepeater::products(...) instance via Reflection so the test can
 * invoke it directly with simulated get/set callables.
 */
function productSelectClosure(string $fkColumn, string $priceField): Closure
{
    $repeater = LineItemsRepeater::products($fkColumn, $priceField);

    $childComponentsRef = new ReflectionProperty($repeater, 'childComponents');
    $childComponentsRef->setAccessible(true);
    $children = $childComponentsRef->getValue($repeater);
    // Repeater's schema is stored as a flat list under the 'default' key (or
    // numeric-indexed depending on Filament version).
    $list = $children['default'] ?? $children;

    // Position 0 = Hidden FK; position 1 = the product Select.
    $select = $list[1];

    $afterStateUpdatedRef = new ReflectionProperty($select, 'afterStateUpdated');
    $afterStateUpdatedRef->setAccessible(true);
    $callbacks = $afterStateUpdatedRef->getValue($select);

    return $callbacks[0];
}

beforeEach(function () {
    Setting::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'currency',
        'value' => 'USD',
    ]);
});

it('product Select afterStateUpdated has signature ($state, $get, $set)', function () {
    $closure = productSelectClosure('quote_product_id', 'unit_price');

    $ref = new ReflectionFunction($closure);
    $params = $ref->getParameters();

    expect($params)->toHaveCount(3);
    expect($params[0]->getName())->toBe('state');
    expect($params[1]->getName())->toBe('get');
    expect($params[2]->getName())->toBe('set');
});

it('autofills unit_price from the products getDefaultPrice unit_price column divided by 100', function () {
    $product = Product::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Widget',
        'active' => true,
    ]);

    // Stored ×100 — setUnitPriceAttribute multiplies by 100 on save.
    ProductPrice::create([
        'external_id' => (string) Str::uuid(),
        'product_id' => $product->id,
        'unit_price' => 42.50,
        'currency' => 'USD',
    ]);

    $row = ['id' => $product->id, 'quantity' => 1];
    $get = function (string $key) use (&$row) {
        return $row[$key] ?? null;
    };
    $set = function (string $key, $value) use (&$row): void {
        $row[$key] = $value;
    };

    $closure = productSelectClosure('quote_product_id', 'unit_price');
    $closure($product->id, $get, $set);

    expect((float) $row['unit_price'])->toBe(42.50);
    // recalcRow side effect: amount = unit_price * quantity (qty default 1).
    expect((float) $row['amount'])->toBe(42.50);
});

it('writes the Deal price field name when priceField is price', function () {
    $product = Product::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Widget',
        'active' => true,
    ]);

    ProductPrice::create([
        'external_id' => (string) Str::uuid(),
        'product_id' => $product->id,
        'unit_price' => 10,
        'currency' => 'USD',
    ]);

    $row = ['id' => $product->id, 'quantity' => 1];
    $get = function (string $key) use (&$row) {
        return $row[$key] ?? null;
    };
    $set = function (string $key, $value) use (&$row): void {
        $row[$key] = $value;
    };

    $closure = productSelectClosure('deal_product_id', 'price');
    $closure($product->id, $get, $set);

    expect((float) $row['price'])->toBe(10.0);
    expect((float) $row['amount'])->toBe(10.0);
    // Deal rows don't carry tax_amount.
    expect(array_key_exists('tax_amount', $row))->toBeFalse();
});

it('triggers recalcRow tax_amount computation when priceField is unit_price', function () {
    $taxRate = TaxRate::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'GST',
        'rate' => 10,
    ]);

    $product = Product::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Widget',
        'tax_rate_id' => $taxRate->id,
        'active' => true,
    ]);

    ProductPrice::create([
        'external_id' => (string) Str::uuid(),
        'product_id' => $product->id,
        'unit_price' => 100,
        'currency' => 'USD',
    ]);

    $row = ['id' => $product->id, 'quantity' => 2];
    $get = function (string $key) use (&$row) {
        return $row[$key] ?? null;
    };
    $set = function (string $key, $value) use (&$row): void {
        $row[$key] = $value;
    };

    $closure = productSelectClosure('quote_product_id', 'unit_price');
    $closure($product->id, $get, $set);

    expect((float) $row['unit_price'])->toBe(100.0);
    expect((float) $row['amount'])->toBe(200.0);
    expect((float) $row['tax_amount'])->toBe(20.0);
});

it('source contains the bug-fix invariants for US-004', function () {
    $src = file_get_contents(__DIR__ . '/../../src/Concerns/Forms/LineItemsRepeater.php');

    // Prices through LineItemVariation::unitPrice(), i.e. core 2.5.0's
    // Product::unitPriceFor(), which reads the unit_price column of
    // getDefaultPrice() for a line with no variation (NOT $price->price).
    expect($src)->toContain('LineItemVariation::unitPrice($state, null, self::documentCurrency($get))');
    expect($src)->not->toContain('$price->price /');

    // Closure signature picks up $get so it can drive recalc helpers.
    expect($src)->toContain('function ($state, $get, $set) use ($priceField)');

    // After the unit_price $set, both recalc helpers fire.
    expect($src)->toContain('self::recalcRow($get, $set, $priceField);');
    expect($src)->toContain('self::recalcFormTotals($get, $set);');
});
