<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Label;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\ProductPrice;
use VentureDrake\LaravelCrm\Models\ProductVariation;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\ViewOrder;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\CreateQuote;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\EditQuote;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\ViewQuote;
use VentureDrake\LaravelCrmFilament\Support\LineItemVariation;
use VentureDrake\LaravelCrmFilament\Support\OrderDrawdownPrefill;

use function Pest\Livewire\livewire;

/**
 * Core 2.5.0 product variations on line items: the variation select, the
 * price it sets, and the variation surviving edits and conversions.
 */
beforeEach(function () {
    actingAsCrmAdmin();

    Setting::create(['external_id' => (string) Str::uuid(), 'name' => 'currency', 'value' => 'USD']);

    $this->product = Product::create(['external_id' => (string) Str::uuid(), 'name' => 'Ring', 'active' => true]);
    ProductPrice::create(['product_id' => $this->product->id, 'unit_price' => 100, 'currency' => 'USD']);

    $this->gold = ProductVariation::create(['product_id' => $this->product->id, 'name' => 'Gold', 'code' => 'RING-AU', 'order' => 1, 'active' => true]);
    ProductPrice::create(['product_id' => $this->product->id, 'product_variation_id' => $this->gold->id, 'unit_price' => 250, 'currency' => 'USD']);
    ProductPrice::create(['product_id' => $this->product->id, 'product_variation_id' => $this->gold->id, 'unit_price' => 200, 'currency' => 'EUR']);

    // No price of its own: sells at the product price.
    $this->silver = ProductVariation::create(['product_id' => $this->product->id, 'name' => 'Silver', 'order' => 2, 'active' => true]);
});

it('lists a product\'s active variations in order, labelled with their SKU', function () {
    ProductVariation::create(['product_id' => $this->product->id, 'name' => 'Retired', 'order' => 0, 'active' => false]);

    expect(LineItemVariation::options($this->product->id))->toBe([
        $this->gold->id => 'Gold (RING-AU)',
        $this->silver->id => 'Silver',
    ]);
});

it('has no options for a product without variations, or no product', function () {
    $plain = Product::create(['external_id' => (string) Str::uuid(), 'name' => 'Plain', 'active' => true]);

    expect(LineItemVariation::options($plain->id))->toBe([])
        ->and(LineItemVariation::options(null))->toBe([]);
});

it('keeps a deleted or deactivated variation in the options of a line already on it', function () {
    $this->gold->delete();

    expect(LineItemVariation::options($this->product->id))->not->toHaveKey($this->gold->id)
        ->and(LineItemVariation::options($this->product->id, $this->gold->id))->toHaveKey($this->gold->id);
});

it('does not resolve another product\'s variation', function () {
    $other = Product::create(['external_id' => (string) Str::uuid(), 'name' => 'Other', 'active' => true]);

    expect(LineItemVariation::resolve($other->id, $this->gold->id))->toBeNull()
        ->and(LineItemVariation::options($other->id, $this->gold->id))->toBe([]);
});

it('prices a line from the variation in the document currency, else the product price', function () {
    expect(LineItemVariation::unitPrice($this->product->id, $this->gold->id, 'USD'))->toBe(250.0)
        ->and(LineItemVariation::unitPrice($this->product->id, $this->gold->id, 'EUR'))->toBe(200.0)
        // No GBP price on the variation: the product's default price.
        ->and(LineItemVariation::unitPrice($this->product->id, $this->gold->id, 'GBP'))->toBe(100.0)
        ->and(LineItemVariation::unitPrice($this->product->id, $this->silver->id, 'USD'))->toBe(100.0)
        ->and(LineItemVariation::unitPrice($this->product->id, null, 'USD'))->toBe(100.0)
        ->and(LineItemVariation::unitPrice(999999, null, 'USD'))->toBeNull();
});

it('reprices the row when a variation is picked, and saves it on the quote line', function () {
    livewire(CreateQuote::class)
        ->fillForm(['title' => 'Rings', 'currency' => 'USD'])
        ->set('data.products', ['row1' => ['id' => $this->product->id, 'quantity' => 2]])
        ->set('data.products.row1.product_variation_id', $this->gold->id)
        ->assertSet('data.products.row1.unit_price', 250.0)
        ->assertSet('data.products.row1.amount', 500.0)
        ->call('create')
        ->assertHasNoFormErrors();

    $line = Quote::where('title', 'Rings')->first()->quoteProducts()->first();

    expect($line->product_variation_id)->toBe($this->gold->id)
        ->and($line->price)->toBe(25000)
        ->and($line->displayName())->toBe('Ring — Gold');
});

it('prices the variation in the quote\'s currency', function () {
    livewire(CreateQuote::class)
        ->fillForm(['title' => 'Rings', 'currency' => 'EUR'])
        ->set('data.products', ['row1' => ['id' => $this->product->id, 'quantity' => 1]])
        ->set('data.products.row1.product_variation_id', $this->gold->id)
        ->assertSet('data.products.row1.unit_price', 200.0);
});

it('clears the variation when the product changes', function () {
    $other = Product::create(['external_id' => (string) Str::uuid(), 'name' => 'Chain', 'active' => true]);

    livewire(CreateQuote::class)
        ->fillForm(['title' => 'Rings', 'currency' => 'USD'])
        ->set('data.products', ['row1' => ['id' => $this->product->id, 'quantity' => 1]])
        ->set('data.products.row1.product_variation_id', $this->gold->id)
        ->set('data.products.row1.id', $other->id)
        ->assertSet('data.products.row1.product_variation_id', null);
});

it('loads the line\'s variation into the edit form', function () {
    $quote = Quote::create(['external_id' => (string) Str::uuid(), 'title' => 'Rings', 'currency' => 'USD']);
    $quote->quoteProducts()->create([
        'product_id' => $this->product->id,
        'product_variation_id' => $this->gold->id,
        'quantity' => 1,
        'price' => 250,
        'amount' => 250,
        'currency' => 'USD',
    ]);

    $products = livewire(EditQuote::class, ['record' => $quote->external_id])->get('data.products');

    // Filament keeps a select's state as the option key's string form.
    expect((int) collect($products)->first()['product_variation_id'])->toBe($this->gold->id);
});

it('carries the variation from a quote line to the order line on conversion', function () {
    $quote = Quote::create(['external_id' => (string) Str::uuid(), 'title' => 'Rings', 'currency' => 'USD']);
    $quoteProduct = $quote->quoteProducts()->create([
        'product_id' => $this->product->id,
        'product_variation_id' => $this->gold->id,
        'quantity' => 1,
        'price' => 250,
        'amount' => 250,
        'currency' => 'USD',
    ]);

    $method = new ReflectionMethod(ViewQuote::class, 'buildOrderPayloadFromQuote');
    $payload = $method->invoke(new ViewQuote, $quote->fresh());

    expect($payload['products'][0]['product_variation_id'])->toBe($this->gold->id)
        ->and($payload['products'][0]['quote_product_id'])->toBe($quoteProduct->id);
});

it('carries the variation from an order line onto the invoice and purchase-order prefills', function () {
    $order = Order::create(['external_id' => (string) Str::uuid(), 'currency' => 'USD']);
    $order->orderProducts()->create([
        'product_id' => $this->product->id,
        'product_variation_id' => $this->gold->id,
        'quantity' => 1,
        'price' => 250,
        'amount' => 250,
        'currency' => 'USD',
    ]);

    expect(OrderDrawdownPrefill::invoiceProducts($order->fresh())[0]['product_variation_id'])->toBe($this->gold->id);

    $method = new ReflectionMethod(ViewOrder::class, 'buildPurchaseOrderPayloadsFromOrder');
    $payloads = $method->invoke(new ViewOrder, $order->fresh());

    expect($payloads[0]['products'][0]['product_variation_id'])->toBe($this->gold->id)
        // The variation's own price row, not the product's.
        ->and($payloads[0]['products'][0]['unit_price'])->toBe(250.0);
});

it('buys a variation line at the product\'s supplier cost, not the variation\'s selling price', function () {
    // Core never writes cost_per_unit to a variation's price row.
    ProductPrice::where('product_id', $this->product->id)->whereNull('product_variation_id')->update(['cost_per_unit' => 6000]);

    $order = Order::create(['external_id' => (string) Str::uuid(), 'currency' => 'USD']);
    $order->orderProducts()->create([
        'product_id' => $this->product->id,
        'product_variation_id' => $this->gold->id,
        'quantity' => 1,
        'price' => 250,
        'amount' => 250,
        'currency' => 'USD',
    ]);

    $method = new ReflectionMethod(ViewOrder::class, 'buildPurchaseOrderPayloadsFromOrder');

    expect($method->invoke(new ViewOrder, $order->fresh())[0]['products'][0]['unit_price'])->toBe(60.0)
        ->and(LineItemVariation::supplierUnitPrice($this->product->id, $this->gold->id, 'USD', null))->toBe(60.0)
        ->and(LineItemVariation::supplierUnitPrice(999999, null, 'USD', 4200))->toBe(42.0);
});

it('copies the quote\'s labels onto the order payload', function () {
    // The pluck named the pivot table's `id`, which crm_labelables has never
    // had, so converting any quote threw before the order was created.
    $label = Label::create(['external_id' => (string) Str::uuid(), 'name' => 'Priority', 'hex' => 'ff0000']);
    $quote = Quote::create(['external_id' => (string) Str::uuid(), 'title' => 'Rings', 'currency' => 'USD']);
    $quote->labels()->attach($label);

    $method = new ReflectionMethod(ViewQuote::class, 'buildOrderPayloadFromQuote');

    expect($method->invoke(new ViewQuote, $quote->fresh())['labels'])->toBe([$label->id]);
});
