<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\LineItemsRepeater;

/**
 * The line-item product picker is searched on the server and capped at 20,
 * as core 2.7.0's is, instead of loading the whole catalogue into every row.
 */
function pickerProduct(string $name, array $attributes = []): Product
{
    return Product::create(array_merge([
        'external_id' => (string) Str::uuid(),
        'name' => $name,
        'active' => true,
    ], $attributes));
}

it('caps the picker at 20 products, ordered by name', function () {
    foreach (range(1, 25) as $i) {
        pickerProduct(sprintf('Widget %02d', $i));
    }

    $results = LineItemsRepeater::productSearchResults(null);

    expect($results)->toHaveCount(20)
        ->and(array_values($results)[0])->toBe('Widget 01')
        ->and(array_values($results)[19])->toBe('Widget 20');
});

it('matches the name or the code, ignoring case', function () {
    $bolt = pickerProduct('Hex bolt');
    $nut = pickerProduct('Lock nut', ['code' => 'NUT-77']);
    pickerProduct('Washer');

    expect(LineItemsRepeater::productSearchResults('HEX'))->toBe([$bolt->id => 'Hex bolt'])
        ->and(LineItemsRepeater::productSearchResults('nut-77'))->toBe([$nut->id => 'Lock nut']);
});

it('matches % and _ literally', function () {
    $percent = pickerProduct('100% cotton');
    pickerProduct('1000 cotton');

    expect(LineItemsRepeater::productSearchResults('0%'))->toBe([$percent->id => '100% cotton']);
});

it('leaves inactive products out of the search', function () {
    pickerProduct('Retired gadget', ['active' => false]);

    expect(LineItemsRepeater::productSearchResults('gadget'))->toBe([]);
});

it('still labels a line whose product was deleted or deactivated', function () {
    $deleted = pickerProduct('Old part');
    $deleted->delete();
    $inactive = pickerProduct('Paused part', ['active' => false]);

    expect(LineItemsRepeater::productLabel($deleted->id))->toBe('Old part')
        ->and(LineItemsRepeater::productLabel($inactive->id))->toBe('Paused part')
        ->and(LineItemsRepeater::productLabel(null))->toBeNull();
});

it('wires the product select to the server search rather than a preloaded list', function () {
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Concerns/Forms/LineItemsRepeater.php');

    expect($source)->toContain('->getSearchResultsUsing(fn (?string $search): array => self::productSearchResults($search))')
        ->and($source)->toContain('->getOptionLabelUsing(fn ($value): ?string => self::productLabel($value))')
        ->and($source)->toContain('->createOptionUsing(');
});
