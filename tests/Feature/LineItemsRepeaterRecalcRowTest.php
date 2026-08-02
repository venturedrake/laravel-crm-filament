<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\TaxRate;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\LineItemsRepeater;

function recalcRowInvoke(array $row, string $priceField): array
{
    $get = fn (string $key) => $row[$key] ?? null;
    $set = function (string $key, $value) use (&$row): void {
        $row[$key] = $value;
    };

    $ref = new ReflectionMethod(LineItemsRepeater::class, 'recalcRow');
    $ref->setAccessible(true);
    $ref->invokeArgs(null, [$get, $set, $priceField]);

    return $row;
}

it('declares recalcRow as a private static helper accepting get/set/priceField', function () {
    $ref = new ReflectionMethod(LineItemsRepeater::class, 'recalcRow');

    expect($ref->isStatic())->toBeTrue();
    expect($ref->isPrivate())->toBeTrue();

    $params = $ref->getParameters();
    expect($params)->toHaveCount(3);
    expect($params[0]->getName())->toBe('get');
    expect($params[1]->getName())->toBe('set');
    expect($params[2]->getName())->toBe('priceField');
    expect((string) $params[2]->getType())->toBe('string');
});

it('writes amount = unit_price * quantity with no tax included', function () {
    $row = recalcRowInvoke([
        'id' => null,
        'unit_price' => 25,
        'quantity' => 4,
    ], 'unit_price');

    expect($row['amount'])->toBe(100.0);
});

it('writes amount for Deal price field without touching tax_amount', function () {
    $row = recalcRowInvoke([
        'id' => null,
        'price' => 12.5,
        'quantity' => 3,
    ], 'price');

    expect($row['amount'])->toBe(37.5);
    expect(array_key_exists('tax_amount', $row))->toBeFalse();
});

it('computes tax_amount from product taxRate relation when priceField is unit_price', function () {
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

    $row = recalcRowInvoke([
        'id' => $product->id,
        'unit_price' => 200,
        'quantity' => 1,
    ], 'unit_price');

    expect($row['amount'])->toBe(200.0);
    expect($row['tax_amount'])->toBe(20.0);
});

it('falls back to default TaxRate when product has no rate', function () {
    TaxRate::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Default',
        'rate' => 5,
        'default' => 1,
    ]);

    $row = recalcRowInvoke([
        'id' => null,
        'unit_price' => 50,
        'quantity' => 2,
    ], 'unit_price');

    expect($row['amount'])->toBe(100.0);
    expect($row['tax_amount'])->toBe(5.0);
});

it('falls back to the tax_rate setting when no TaxRate row is flagged default', function () {
    // SettingService::get() reads a name => value array, so it hands back the
    // scalar rate — resolveTaxRate() must cast that, not read ->value off it.
    $settings = app('laravel-crm.settings');
    $settings->set('tax_rate', '15');
    $settings->forgetCache();

    $row = recalcRowInvoke([
        'id' => null,
        'unit_price' => 40,
        'quantity' => 2,
    ], 'unit_price');

    expect($row['amount'])->toBe(80.0);
    expect($row['tax_amount'])->toBe(12.0);
});

it('prefers a default TaxRate row over the tax_rate setting', function () {
    TaxRate::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Default',
        'rate' => 5,
        'default' => 1,
    ]);

    $settings = app('laravel-crm.settings');
    $settings->set('tax_rate', '15');
    $settings->forgetCache();

    $row = recalcRowInvoke([
        'id' => null,
        'unit_price' => 100,
        'quantity' => 1,
    ], 'unit_price');

    expect($row['tax_amount'])->toBe(5.0);
});

it('writes zero tax_amount when no rate is resolvable', function () {
    $row = recalcRowInvoke([
        'id' => null,
        'unit_price' => 10,
        'quantity' => 3,
    ], 'unit_price');

    expect($row['amount'])->toBe(30.0);
    expect($row['tax_amount'])->toBe(0.0);
});

it('handles zero / non-numeric inputs gracefully', function () {
    $row = recalcRowInvoke([
        'id' => null,
        'unit_price' => null,
        'quantity' => null,
    ], 'unit_price');

    expect($row['amount'])->toBe(0.0);
    expect($row['tax_amount'])->toBe(0.0);
});
