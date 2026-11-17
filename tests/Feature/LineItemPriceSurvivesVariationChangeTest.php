<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\ProductPrice;
use VentureDrake\LaravelCrm\Models\ProductVariation;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrm\Models\TaxRate;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\CreateQuote;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\EditQuote;

use function Pest\Livewire\livewire;

/**
 * QA F01 / QU-3016: a unit price typed straight after picking a variation was
 * lost - the variation's price was saved instead.
 *
 * Typed while the variation's request is in flight, the price goes out in the
 * next request together with the variation. Livewire applies both before it
 * runs either field's hook, so the variation hook used to overwrite the typed
 * price. `update(updates: [...])` sends one such payload; a test `set()` of an
 * array would not - it sends one request per key.
 */
beforeEach(function () {
    actingAsCrmAdmin();

    Setting::create(['external_id' => (string) Str::uuid(), 'name' => 'currency', 'value' => 'USD']);
    TaxRate::create(['external_id' => (string) Str::uuid(), 'name' => 'GST', 'rate' => 10, 'default' => true]);

    $this->product = Product::create(['external_id' => (string) Str::uuid(), 'name' => 'Widget', 'active' => true]);
    ProductPrice::create(['product_id' => $this->product->id, 'unit_price' => 15, 'currency' => 'USD']);

    $this->standard = ProductVariation::create(['product_id' => $this->product->id, 'name' => 'Standard', 'order' => 1, 'active' => true]);
    ProductPrice::create(['product_id' => $this->product->id, 'product_variation_id' => $this->standard->id, 'unit_price' => 12.34, 'currency' => 'USD']);

    $this->premium = ProductVariation::create(['product_id' => $this->product->id, 'name' => 'Premium', 'order' => 2, 'active' => true]);
    ProductPrice::create(['product_id' => $this->product->id, 'product_variation_id' => $this->premium->id, 'unit_price' => 19.99, 'currency' => 'USD']);
});

/**
 * 17.50 x 2.5 = 43.75, 10% tax 4.38 on the row; total 48.13 as saved.
 */
function assertTypedPriceRow($component, string $row): void
{
    $component
        ->assertSet("data.products.{$row}.unit_price", '17.50')
        ->assertSet("data.products.{$row}.amount", 43.75)
        ->assertSet("data.products.{$row}.tax_amount", 4.38);
}

function assertTypedPriceSaved(Quote $quote): void
{
    $line = $quote->quoteProducts()->first();

    expect($line->price)->toBe(1750)
        ->and($line->amount)->toBe(4375)
        ->and($line->tax_amount)->toBe(438)
        ->and((int) $quote->total)->toBe(4813);
}

it('keeps a price typed in the same request as a variation change, and creates the quote with it', function () {
    $component = livewire(CreateQuote::class)
        ->fillForm(['title' => 'Widgets', 'currency' => 'USD'])
        ->set('data.products', ['row1' => ['id' => $this->product->id, 'quantity' => 1]])
        ->set('data.products.row1.id', $this->product->id)
        ->update(updates: [
            'data.products.row1.product_variation_id' => $this->premium->id,
            'data.products.row1.unit_price' => '17.50',
            'data.products.row1.quantity' => '2.5',
        ]);

    assertTypedPriceRow($component, 'row1');

    $component->call('create')->assertHasNoFormErrors();

    $quote = Quote::where('title', 'Widgets')->first();

    expect($quote->quoteProducts()->first()->product_variation_id)->toBe($this->premium->id);
    assertTypedPriceSaved($quote);
});

it('keeps a price typed in the same request as a variation change on edit, and saves it', function () {
    $quote = Quote::create(['external_id' => (string) Str::uuid(), 'title' => 'Widgets', 'currency' => 'USD']);
    $quote->quoteProducts()->create([
        'product_id' => $this->product->id,
        'product_variation_id' => $this->premium->id,
        'quantity' => 1,
        'price' => 1999,
        'amount' => 1999,
        'tax_amount' => 200,
        'currency' => 'USD',
    ]);

    $component = livewire(EditQuote::class, ['record' => $quote->external_id]);
    $row = array_key_first($component->get('data.products'));

    $component->update(updates: [
        "data.products.{$row}.product_variation_id" => $this->standard->id,
        "data.products.{$row}.unit_price" => '17.50',
        "data.products.{$row}.quantity" => '2.5',
    ]);

    assertTypedPriceRow($component, $row);

    $component->call('save')->assertHasNoFormErrors();

    $quote->refresh();
    expect($quote->quoteProducts()->first()->product_variation_id)->toBe($this->standard->id);
    assertTypedPriceSaved($quote);

    // Reopened, the edit form shows what was typed.
    $reopened = livewire(EditQuote::class, ['record' => $quote->external_id]);
    $line = collect($reopened->get('data.products'))->first();

    expect((float) $line['unit_price'])->toBe(17.5)
        ->and((float) $line['amount'])->toBe(43.75)
        ->and((float) $line['tax_amount'])->toBe(4.38);
});

it('keeps a price typed in the same request as a product change, and still clears the variation', function () {
    $other = Product::create(['external_id' => (string) Str::uuid(), 'name' => 'Gadget', 'active' => true]);
    ProductPrice::create(['product_id' => $other->id, 'unit_price' => 30, 'currency' => 'USD']);

    livewire(CreateQuote::class)
        ->fillForm(['title' => 'Widgets', 'currency' => 'USD'])
        ->set('data.products', ['row1' => ['id' => $this->product->id, 'quantity' => 1]])
        ->set('data.products.row1.product_variation_id', $this->premium->id)
        ->assertSet('data.products.row1.unit_price', 19.99)
        ->update(updates: [
            'data.products.row1.id' => $other->id,
            'data.products.row1.unit_price' => '17.50',
        ])
        ->assertSet('data.products.row1.product_variation_id', null)
        ->assertSet('data.products.row1.unit_price', '17.50')
        ->assertSet('data.products.row1.amount', 17.5);
});

it('still reprices the row when a variation change comes with other fields but no new price', function () {
    livewire(CreateQuote::class)
        ->fillForm(['title' => 'Widgets', 'currency' => 'USD'])
        ->set('data.products', ['row1' => ['id' => $this->product->id, 'quantity' => 1]])
        ->set('data.products.row1.id', $this->product->id)
        ->assertSet('data.products.row1.unit_price', 15.0)
        ->update(updates: [
            'data.products.row1.product_variation_id' => $this->premium->id,
            'data.products.row1.quantity' => '2',
        ])
        ->assertSet('data.products.row1.unit_price', 19.99)
        ->assertSet('data.products.row1.amount', 39.98);
});

it('keeps a price typed after the variation change has settled', function () {
    $component = livewire(CreateQuote::class)
        ->fillForm(['title' => 'Widgets', 'currency' => 'USD'])
        ->set('data.products', ['row1' => ['id' => $this->product->id, 'quantity' => '2.5']])
        ->set('data.products.row1.product_variation_id', $this->premium->id)
        ->assertSet('data.products.row1.unit_price', 19.99)
        ->set('data.products.row1.unit_price', '17.50');

    assertTypedPriceRow($component, 'row1');
});

it('renders the typed-price guard on each row\'s unit-price input', function () {
    $quote = Quote::create(['external_id' => (string) Str::uuid(), 'title' => 'Widgets', 'currency' => 'USD']);
    $quote->quoteProducts()->create([
        'product_id' => $this->product->id,
        'quantity' => 1,
        'price' => 1500,
        'amount' => 1500,
        'currency' => 'USD',
    ]);

    livewire(CreateQuote::class)
        ->fillForm(['title' => 'Widgets', 'currency' => 'USD'])
        ->set('data.products', ['row1' => ['id' => $this->product->id, 'quantity' => 1]])
        ->assertSeeHtml('$wire.$interceptMessage')
        ->assertSeeHtml("'data.products.row1.unit_price'");

    $component = livewire(EditQuote::class, ['record' => $quote->external_id]);
    $row = array_key_first($component->get('data.products'));

    $component
        ->assertSeeHtml('$wire.$interceptMessage')
        ->assertSeeHtml("'data.products.{$row}.unit_price'");
});
