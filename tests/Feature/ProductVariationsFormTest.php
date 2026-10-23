<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\ProductPrice;
use VentureDrake\LaravelCrm\Models\ProductVariation;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\RelationManagers\ProductVariationsRelationManager;
use VentureDrake\LaravelCrmFilament\Resources\Products\Pages\CreateProduct;
use VentureDrake\LaravelCrmFilament\Resources\Products\Pages\EditProduct;
use VentureDrake\LaravelCrmFilament\Resources\Products\Pages\ListProducts;
use VentureDrake\LaravelCrmFilament\Resources\Products\Pages\ViewProduct;

use function Pest\Livewire\livewire;

/**
 * Core 2.5.0 product variations on the product form, show page and index.
 */
beforeEach(function () {
    actingAsCrmAdmin();

    Setting::create(['external_id' => (string) Str::uuid(), 'name' => 'currency', 'value' => 'USD']);
});

function productWithVariations(): Product
{
    $product = Product::create(['external_id' => (string) Str::uuid(), 'name' => 'Ring', 'active' => true]);
    ProductPrice::create(['product_id' => $product->id, 'unit_price' => 100, 'currency' => 'USD']);

    $gold = ProductVariation::create(['product_id' => $product->id, 'name' => 'Gold', 'code' => 'RING-AU', 'order' => 1, 'active' => true]);
    ProductPrice::create(['product_id' => $product->id, 'product_variation_id' => $gold->id, 'unit_price' => 250, 'currency' => 'USD']);

    ProductVariation::create(['product_id' => $product->id, 'name' => 'Silver', 'order' => 2, 'active' => false]);

    return $product;
}

it('creates the variations submitted with a new product', function () {
    livewire(CreateProduct::class)
        ->fillForm(['name' => 'Ring', 'unit_price' => 100, 'currency' => 'USD'])
        ->set('data.variations', [
            'a' => ['name' => 'Gold', 'code' => 'RING-AU', 'barcode' => null, 'unit_price' => 250, 'active' => true],
            'b' => ['name' => 'Silver', 'code' => null, 'barcode' => null, 'unit_price' => null, 'active' => false],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::where('name', 'Ring')->firstOrFail();
    $variations = $product->productVariations()->get();

    expect($variations->pluck('name')->all())->toBe(['Gold', 'Silver'])
        ->and($variations->pluck('order')->all())->toBe([1, 2])
        ->and($variations[1]->active)->toBeFalse()
        ->and($variations[0]->priceFor('USD')->unit_price)->toBe(25000)
        // A variation without a price has no row of its own.
        ->and($variations[1]->priceFor('USD'))->toBeNull()
        // ...and the product's own price is not one of the variation rows.
        ->and($product->getDefaultPrice()->unit_price)->toBe(10000);
});

it('requires a name on each variation', function () {
    livewire(CreateProduct::class)
        ->fillForm(['name' => 'Ring', 'unit_price' => 100, 'currency' => 'USD'])
        ->set('data.variations', ['a' => ['name' => '', 'active' => true]])
        ->call('create')
        ->assertHasFormErrors(['variations.a.name' => 'required']);
});

it('loads the variations into the edit form, priced in the product currency', function () {
    $product = productWithVariations();

    $rows = array_values(livewire(EditProduct::class, ['record' => $product->external_id])->get('data.variations'));

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toMatchArray(['name' => 'Gold', 'code' => 'RING-AU', 'unit_price' => 250.0, 'active' => true])
        ->and($rows[1])->toMatchArray(['name' => 'Silver', 'unit_price' => null, 'active' => false]);
});

it('loads the product price into the edit form, and keeps it on save', function () {
    $product = productWithVariations();

    livewire(EditProduct::class, ['record' => $product->external_id])
        ->assertSet('data.unit_price', 100.0)
        ->fillForm(['name' => 'Gold ring'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->fresh()->getPrimaryPrice()->unit_price)->toBe(10000);
});

it('soft-deletes a variation removed from the edit form, and reorders the rest', function () {
    $product = productWithVariations();
    [$gold, $silver] = $product->productVariations()->get()->all();

    livewire(EditProduct::class, ['record' => $product->external_id])
        ->set('data.variations', [
            'x' => ['id' => $silver->id, 'name' => 'Silver', 'code' => 'RING-AG', 'barcode' => null, 'unit_price' => 120, 'active' => true],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(ProductVariation::find($gold->id))->toBeNull()
        ->and(ProductVariation::withTrashed()->find($gold->id)->trashed())->toBeTrue();

    $silver->refresh();

    expect($silver->code)->toBe('RING-AG')
        ->and($silver->order)->toBe(1)
        ->and($silver->active)->toBeTrue()
        ->and($silver->priceFor('USD')->unit_price)->toBe(12000);
});

it('lists the variations on the show page with their own price or the product price', function () {
    $product = productWithVariations();

    livewire(ProductVariationsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => ViewProduct::class])
        ->assertCanSeeTableRecords($product->productVariations()->get())
        ->assertSee('RING-AU')
        ->assertSee('$250.00')
        // Silver has no price of its own: it sells at the product price.
        ->assertSee('$100.00');
});

it('finds a product by a variation SKU on the index', function () {
    $product = productWithVariations();
    $other = Product::create(['external_id' => (string) Str::uuid(), 'name' => 'Chain', 'active' => true]);

    livewire(ListProducts::class)
        ->searchTable('RING-AU')
        ->assertCanSeeTableRecords([$product])
        ->assertCanNotSeeTableRecords([$other]);
});
