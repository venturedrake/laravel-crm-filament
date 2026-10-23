<?php

use Filament\Schemas\Components\Grid;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrm\Models\TaxRate;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\LineItemsRepeater;

/**
 * Walks the LineItemsRepeater's nested Grid to extract the unit_price /
 * price / quantity TextInput's afterStateUpdated closure for direct
 * invocation. Row layout:
 *   - children[0]: Hidden FK
 *   - children[1]: product Select
 *   - children[2]: variation Select (core 2.5.0)
 *   - children[3]: nested Grid containing [unit_price|price, quantity, tax_amount?, amount]
 *   - children[4]: comments Textarea
 */
function row2FieldClosure(string $fkColumn, string $priceField, string $fieldName): Closure
{
    $repeater = LineItemsRepeater::products($fkColumn, $priceField);

    $childComponentsRef = new ReflectionProperty($repeater, 'childComponents');
    $childComponentsRef->setAccessible(true);
    $children = $childComponentsRef->getValue($repeater);
    $list = $children['default'] ?? $children;

    // The nested Grid holding row 2.
    $grid = collect($list)->first(fn ($component) => $component instanceof Grid);
    $gridChildrenRef = new ReflectionProperty($grid, 'childComponents');
    $gridChildrenRef->setAccessible(true);
    $gridChildren = $gridChildrenRef->getValue($grid);
    $gridList = $gridChildren['default'] ?? $gridChildren;

    foreach ($gridList as $component) {
        if (method_exists($component, 'getName') && $component->getName() === $fieldName) {
            $afterStateUpdatedRef = new ReflectionProperty($component, 'afterStateUpdated');
            $afterStateUpdatedRef->setAccessible(true);
            $callbacks = $afterStateUpdatedRef->getValue($component);

            return $callbacks[0];
        }
    }

    throw new RuntimeException("Could not find field '{$fieldName}' in row 2 grid.");
}

beforeEach(function () {
    Setting::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'currency',
        'value' => 'USD',
    ]);
});

it('unit_price afterStateUpdated has signature ($state, $get, $set) and captures $priceField', function () {
    $closure = row2FieldClosure('quote_product_id', 'unit_price', 'unit_price');

    $ref = new ReflectionFunction($closure);
    $params = $ref->getParameters();

    expect($params)->toHaveCount(3);
    expect($params[0]->getName())->toBe('state');
    expect($params[1]->getName())->toBe('get');
    expect($params[2]->getName())->toBe('set');
    expect($ref->getStaticVariables())->toHaveKey('priceField');
});

it('quantity afterStateUpdated has signature ($state, $get, $set) and captures $priceField', function () {
    $closure = row2FieldClosure('quote_product_id', 'unit_price', 'quantity');

    $ref = new ReflectionFunction($closure);
    $params = $ref->getParameters();

    expect($params)->toHaveCount(3);
    expect($params[0]->getName())->toBe('state');
    expect($params[1]->getName())->toBe('get');
    expect($params[2]->getName())->toBe('set');
    expect($ref->getStaticVariables())->toHaveKey('priceField');
});

it('editing unit_price updates the line amount (subtotal only — no tax added)', function () {
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

    $row = ['id' => $product->id, 'unit_price' => 50.0, 'quantity' => 3];
    $get = function (string $key) use (&$row) {
        return $row[$key] ?? null;
    };
    $set = function (string $key, $value) use (&$row): void {
        $row[$key] = $value;
    };

    $closure = row2FieldClosure('quote_product_id', 'unit_price', 'unit_price');
    $closure(50.0, $get, $set);

    // amount = unit_price * qty — subtotal only, tax NOT included.
    expect((float) $row['amount'])->toBe(150.0);
    // tax_amount written separately based on resolved rate (10% of 150).
    expect((float) $row['tax_amount'])->toBe(15.0);
});

it('editing quantity updates the line amount (subtotal only — no tax added)', function () {
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

    $row = ['id' => $product->id, 'unit_price' => 20.0, 'quantity' => 5];
    $get = function (string $key) use (&$row) {
        return $row[$key] ?? null;
    };
    $set = function (string $key, $value) use (&$row): void {
        $row[$key] = $value;
    };

    $closure = row2FieldClosure('quote_product_id', 'unit_price', 'quantity');
    $closure(5, $get, $set);

    // amount = unit_price * qty — subtotal only.
    expect((float) $row['amount'])->toBe(100.0);
    expect((float) $row['tax_amount'])->toBe(10.0);
});

it('Deal price closure routes through recalcRow without setting tax_amount', function () {
    $product = Product::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Widget',
        'active' => true,
    ]);

    $row = ['id' => $product->id, 'price' => 25.0, 'quantity' => 4];
    $get = function (string $key) use (&$row) {
        return $row[$key] ?? null;
    };
    $set = function (string $key, $value) use (&$row): void {
        $row[$key] = $value;
    };

    $closure = row2FieldClosure('deal_product_id', 'price', 'price');
    $closure(25.0, $get, $set);

    expect((float) $row['amount'])->toBe(100.0);
    expect(array_key_exists('tax_amount', $row))->toBeFalse();
});

it('source contains recalc-helper invocations and no inline tax-into-amount math', function () {
    $src = file_get_contents(__DIR__ . '/../../src/Concerns/Forms/LineItemsRepeater.php');

    // Both inline arithmetic patterns from the pre-US-005 code are gone.
    expect($src)->not->toContain("\$set('amount', ((float) \$state * \$qty) + \$tax);");
    expect($src)->not->toContain('(float) $get($priceField) + $tax');

    // The unit_price + quantity closures now both delegate to the helpers.
    // There are four total call sites (product Select + variation Select +
    // unit_price + quantity).
    $recalcRowCount = substr_count($src, 'self::recalcRow($get, $set, $priceField);');
    expect($recalcRowCount)->toBe(4);

    $recalcFormTotalsCount = substr_count($src, 'self::recalcFormTotals($get, $set);');
    expect($recalcFormTotalsCount)->toBe(4);
});
