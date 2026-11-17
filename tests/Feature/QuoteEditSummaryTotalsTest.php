<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\ProductPrice;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrm\Models\TaxRate;
use VentureDrake\LaravelCrmFilament\Resources\Deals\Pages\CreateDeal;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\CreateQuote;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\EditQuote;
use VentureDrake\LaravelCrmFilament\Support\MoneyForm;

use function Pest\Livewire\livewire;

/**
 * QA F02 / QU-3016: on the Quote edit form the summary (Subtotal / Tax /
 * Total) kept the values it loaded with until the page was reloaded, and
 * Subtotal was blank.
 *
 * The line items' live recalc wrote to the wrong state paths, the edit page
 * read `sub_total` where the column is `subtotal`, and nothing put the stored
 * totals back after a save.
 */
beforeEach(function () {
    actingAsCrmAdmin();

    Setting::create(['external_id' => (string) Str::uuid(), 'name' => 'currency', 'value' => 'USD']);
    TaxRate::create(['external_id' => (string) Str::uuid(), 'name' => 'GST', 'rate' => 10, 'default' => true]);

    $this->product = Product::create(['external_id' => (string) Str::uuid(), 'name' => 'Widget', 'active' => true]);
    ProductPrice::create(['product_id' => $this->product->id, 'unit_price' => 15, 'currency' => 'USD']);
});

/**
 * One line, 19.99 x 2.5 = 49.98, 10% tax 5.00; total 54.98.
 */
function summaryTotalsQuote(Product $product): Quote
{
    $quote = Quote::create(['external_id' => (string) Str::uuid(), 'title' => 'Widgets', 'currency' => 'USD']);
    // The models' setters take an amount and store cents; tax_amount has no
    // setter and is stored as given.
    $quote->quoteProducts()->create([
        'product_id' => $product->id,
        'quantity' => 2.5,
        'price' => 19.99,
        'amount' => 49.98,
        'tax_amount' => 500,
        'currency' => 'USD',
    ]);

    $quote->subtotal = 49.98;
    $quote->tax = 5.00;
    $quote->total = 54.98;
    $quote->save();

    return $quote;
}

/**
 * The summary on the form is what was stored.
 */
function assertSummaryMatchesStored($component, Quote $quote): void
{
    $quote->refresh();

    $component
        ->assertSet('data.sub_total', MoneyForm::centsToForm($quote->subtotal))
        ->assertSet('data.tax', MoneyForm::centsToForm($quote->tax))
        ->assertSet('data.total', MoneyForm::centsToForm($quote->total));
}

it('fills the summary on edit, updates it live when a price changes, and keeps the saved totals', function () {
    $quote = summaryTotalsQuote($this->product);

    $component = livewire(EditQuote::class, ['record' => $quote->external_id])
        ->assertSet('data.sub_total', 49.98)
        ->assertSet('data.tax', 5.0)
        ->assertSet('data.total', 54.98);

    $row = array_key_first($component->get('data.products'));

    $component
        ->set("data.products.{$row}.unit_price", '17.50')
        ->assertSet('data.sub_total', 43.75)
        ->assertSet('data.tax', 4.38)
        ->assertSet('data.total', 48.13);

    $component->call('save')->assertHasNoFormErrors();

    $component
        ->assertSet('data.sub_total', 43.75)
        ->assertSet('data.tax', 4.38)
        ->assertSet('data.total', 48.13);

    assertSummaryMatchesStored($component, $quote);
    expect((int) $quote->total)->toBe(4813);
});

it('includes an added line in the summary live and after save', function () {
    $quote = summaryTotalsQuote($this->product);

    $component = livewire(EditQuote::class, ['record' => $quote->external_id]);
    $products = $component->get('data.products');
    $products['new1'] = ['id' => $this->product->id, 'quantity' => 3];

    // 15.00 x 3 = 45.00, tax 4.50.
    $component
        ->set('data.products', $products)
        ->set('data.products.new1.id', $this->product->id)
        ->assertSet('data.products.new1.amount', 45.0)
        ->assertSet('data.sub_total', 94.98)
        ->assertSet('data.tax', 9.5)
        ->assertSet('data.total', 104.48);

    $component->call('save')->assertHasNoFormErrors();

    expect($quote->quoteProducts()->count())->toBe(2);
    assertSummaryMatchesStored($component, $quote);
    expect((int) $quote->total)->toBe(10448);
});

it('shows the stored total, with discount and adjustment, after save', function () {
    $quote = summaryTotalsQuote($this->product);

    $component = livewire(EditQuote::class, ['record' => $quote->external_id])
        ->set('data.discount', '5.00')
        ->set('data.adjustment', '1.00');

    $component->call('save')->assertHasNoFormErrors();

    // 49.98 - 5.00 + 5.00 + 1.00, as the service stores it.
    $component
        ->assertSet('data.discount', 5.0)
        ->assertSet('data.adjustment', 1.0)
        ->assertSet('data.total', 50.98);

    assertSummaryMatchesStored($component, $quote);
    expect((int) $quote->total)->toBe(5098);
});

it('updates the summary live on the create form', function () {
    // 15.00 x 2.5 = 37.50, tax 3.75.
    livewire(CreateQuote::class)
        ->fillForm(['title' => 'Widgets', 'currency' => 'USD'])
        ->set('data.products', ['row1' => ['id' => $this->product->id, 'quantity' => '2.5']])
        ->set('data.products.row1.id', $this->product->id)
        ->assertSet('data.sub_total', 37.5)
        ->assertSet('data.tax', 3.75)
        ->assertSet('data.total', 41.25);
});

it('does not add summary fields to the deal form, which has none', function () {
    $component = livewire(CreateDeal::class)
        ->fillForm(['title' => 'Widgets', 'currency' => 'USD'])
        ->set('data.products', ['row1' => ['id' => $this->product->id, 'quantity' => 3]])
        ->set('data.products.row1.price', 100)
        ->assertSet('data.products.row1.amount', 300.0);

    expect($component->get('data'))
        ->not->toHaveKey('sub_total')
        ->not->toHaveKey('tax')
        ->not->toHaveKey('total');
});
