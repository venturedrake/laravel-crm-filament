<?php

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrm\Support\Money;
use VentureDrake\LaravelCrm\Support\Quantity;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\LineItemsRepeater;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\CreateQuote;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * Per-line rounding, mirroring core's ModelProducts.
 *
 * Without it, two lines of 0.5 × $9.99 each store round(4.995 × 100) = 500,
 * summing to 1000 — while a header computed from the unrounded 4.995 + 4.995
 * gives 999. CheckAmount then flags a perfectly consistent document as broken,
 * and the operator has no way to make the red badge go away.
 */
beforeEach(function () {
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'Decimal Tester',
        'email' => 'decimal-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->assignRole('Owner');
    $this->actingAs($this->user->fresh());

    // Product::getDefaultPrice() reads Setting::currency()->value, which is a
    // query builder when the row is absent.
    Setting::updateOrCreate(['name' => 'currency'], ['value' => 'USD']);
});

/**
 * The quantity TextInput, reached by walking the repeater's nested Grid — the
 * same technique LineItemsRepeaterUnitPriceQuantityRecalcTest uses, since a
 * detached component has no container to flatten through.
 */
function decimalQuantityField(): TextInput
{
    $repeater = LineItemsRepeater::products('quote_product_id', 'unit_price');

    $childrenRef = new ReflectionProperty($repeater, 'childComponents');
    $childrenRef->setAccessible(true);
    $children = $childrenRef->getValue($repeater);
    $list = $children['default'] ?? $children;

    foreach ($list as $component) {
        if (! $component instanceof Grid) {
            continue;
        }

        $gridRef = new ReflectionProperty($component, 'childComponents');
        $gridRef->setAccessible(true);
        $gridChildren = $gridRef->getValue($component);

        foreach ($gridChildren['default'] ?? $gridChildren as $field) {
            if ($field instanceof TextInput && $field->getName() === 'quantity') {
                return $field;
            }
        }
    }

    throw new RuntimeException('quantity field not found on the line-items repeater.');
}

it('accepts three decimal places on the quantity field', function () {
    $field = decimalQuantityField();

    expect($field->getStep())->toBe(0.001);

    $source = (string) file_get_contents((new ReflectionClass(LineItemsRepeater::class))->getFileName());

    expect($source)->toContain("->rules(['decimal:0,3'])")
        // Typing "0." used to fire a recalc against a non-numeric partial on
        // every keystroke, across three live fields.
        ->toContain("->live(debounce: '500ms')");
});

it('rounds each line to cents the way core does', function () {
    // 0.5 x 9.99 = 4.995 -> 5.00 per line, so two lines sum to 10.00 and the
    // stored integers sum to 1000. Unrounded they would sum to 9.99.
    $unitPrice = Money::toFloat('9.99');
    $quantity = Quantity::toFloat('0.5');

    $line = round($unitPrice * $quantity, 2);

    expect($line)->toBe(5.0)
        ->and(Money::toInteger($line))->toBe(500)
        ->and(Money::toInteger(round($line + $line, 2)))->toBe(1000);
});

it('persists a fractional quantity through a quote create', function () {
    $product = Product::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Rope (per metre)',
        'active' => true,
    ]);

    livewire(CreateQuote::class)
        ->fillForm([
            'title' => 'Fractional quote',
            'products' => [
                [
                    'id' => $product->id,
                    'unit_price' => 9.99,
                    'quantity' => 0.5,
                    'amount' => 5.0,
                    'tax_amount' => 0,
                ],
                [
                    'id' => $product->id,
                    'unit_price' => 9.99,
                    'quantity' => 0.5,
                    'amount' => 5.0,
                    'tax_amount' => 0,
                ],
            ],
            'sub_total' => 10.0,
            'total' => 10.0,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $quote = Quote::query()->latest('id')->first();

    expect($quote)->not->toBeNull();

    $lines = $quote->quoteProducts;

    expect($lines)->toHaveCount(2);

    foreach ($lines as $line) {
        // decimal(15,3) round-trips 0.5 rather than truncating it to 0.
        expect(Quantity::toFloat($line->quantity))->toBe(0.5)
            ->and((int) $line->amount)->toBe(500);
    }

    // The header agrees with the body, which is what CheckAmount checks.
    expect((int) $lines->sum('amount'))->toBe(1000)
        ->and((int) $quote->subtotal)->toBe(1000);
});

it('normalises rather than casts, so an empty or masked value is not silently zero', function () {
    // (float) '' is 0 and (float) '$1,234.56' is 1 — both wrong, and both
    // silent. Money/Quantity is why recalcRow no longer casts.
    expect(Money::toFloat('$1,234.56'))->toBe(1234.56)
        ->and(Money::toFloat(''))->toBe(0.0)
        ->and(Quantity::toFloat('3.500'))->toBe(3.5)
        ->and(Quantity::format(2.0))->toBe('2')
        ->and(Quantity::format(3.5))->toBe('3.5');
});
