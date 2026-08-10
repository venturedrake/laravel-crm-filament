<?php

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Money\Exception\UnknownCurrencyException;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\ProductPrice;
use VentureDrake\LaravelCrmFilament\RelationManagers\ProductPricesRelationManager;
use VentureDrake\LaravelCrmFilament\Resources\Leads\Pages\ListLeads;
use VentureDrake\LaravelCrmFilament\Resources\Orders\OrderResource;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\ViewOrder;
use VentureDrake\LaravelCrmFilament\Resources\Products\Pages\ViewProduct;
use VentureDrake\LaravelCrmFilament\Support\MoneyForm;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * Money is stored in minor units. Filament's own ->money() takes a $divideBy
 * that defaults to 0 — falsy, so it never divides — which rendered every
 * stored amount 100x too large. These tests pin the rendered output to the
 * package's own money() helper, the one /crm renders through, so the two UIs
 * cannot drift apart again.
 */
beforeEach(function () {
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'Money Tester',
        'email' => 'money-tester' . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->assignRole('Admin');
    $this->actingAs($this->user);
});

/**
 * Writes stored cents straight to the column: the models mutate money on set
 * (x100), so assigning through the model would double-apply the conversion.
 */
function storeCents(string $table, int $id, array $cents): void
{
    DB::table($table)->where('id', $id)->update($cents);
}

it('renders a table money column through the package money() helper', function () {
    $lead = Lead::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Money parity probe',
        'currency' => 'USD',
    ]);

    storeCents($lead->getTable(), $lead->id, ['amount' => 3909286]);

    expect((int) Lead::find($lead->id)->getAttributes()['amount'])->toBe(3909286);

    livewire(ListLeads::class)
        ->assertSee('$39,092.86')
        ->assertDontSee('$3,909,286.00');
});

it('renders an infolist money entry through the package money() helper', function () {
    $order = Order::create([
        'external_id' => (string) Str::uuid(),
    ]);

    storeCents($order->getTable(), $order->id, [
        'subtotal' => 4055730,
        'tax' => 405573,
        'total' => 4461303,
        'currency' => 'USD',
    ]);

    $record = Order::find($order->id);

    // ViewOrder cannot be rendered through Livewire in the harness (its tabs
    // strip trips child-tag validation), so build the page's infolist directly
    // — the convention LeadInfolistTest already established.
    $page = (new ReflectionClass(ViewOrder::class))->newInstanceWithoutConstructor();
    $page->record = $record;

    $schema = Schema::make($page);
    $schema->record($record);
    OrderResource::infolist($schema);

    $entries = [];
    foreach ($schema->getComponents(withHidden: true) as $component) {
        foreach ($component->getChildComponents() as $child) {
            if ($child instanceof TextEntry) {
                $entries[$child->getName()] = $child;
            }
        }
    }

    expect($entries)->toHaveKeys(['subtotal', 'tax', 'total']);

    expect($entries['subtotal']->formatState(4055730))->toBe('$40,557.30')
        ->and($entries['tax']->formatState(405573))->toBe('$4,055.73')
        ->and($entries['total']->formatState(4461303))->toBe('$44,613.03');
});

it('formats stored cents exactly as the package money() helper does', function () {
    expect(MoneyForm::display(4461303, 'USD'))->toBe((string) money(4461303, 'USD'))
        ->and(MoneyForm::display(3909286, 'USD'))->toBe((string) money(3909286, 'USD'))
        // A total that divides evenly is the case the old $cents / 100 sites
        // got wrong: PHP hands money() an int, which it reads as minor units.
        ->and(MoneyForm::display(4461300, 'USD'))->toBe((string) money(4461300, 'USD'))
        ->and(MoneyForm::display(null))->toBeNull()
        ->and(MoneyForm::display(''))->toBeNull();
});

it('pins the Filament ->money() behaviour this replaces', function () {
    // $divideBy defaults to 0, which is falsy, so ->money() never divides:
    // hand it stored cents and it renders 100x too large. This is the whole
    // reason CrmMoney exists — if Filament ever changes the default, this
    // test fails and the workaround can be revisited.
    $page = (new ReflectionClass(ViewOrder::class))->newInstanceWithoutConstructor();
    $page->record = new Order;

    $schema = Schema::make($page)->components([
        TextEntry::make('total')->money('USD'),
    ]);

    $formatted = (string) $schema->getComponents(withHidden: true)[0]->formatState(4461303);

    expect($formatted)->toContain('4,461,303')
        ->and($formatted)->not->toContain('44,613.03');
});

it('falls back to the configured default currency when the record has none', function () {
    config()->set('laravel-crm.default_currency', 'USD');

    expect(MoneyForm::display(4461303))->toBe((string) money(4461303, 'USD'));
});

it('degrades to a plain amount rather than throwing on a currency money() cannot resolve', function () {
    // `currency` is free text in the Product form and the line-item repeater
    // (a TextInput capped at three characters, no ISO validation), and money()
    // throws UnknownCurrencyException on anything that is not an ISO code. A
    // throw from inside formatStateUsing escapes the row and 500s the page, so
    // the one bad record has to degrade to a readable amount instead.
    expect(fn () => (string) money(4461303, 'ZZZ'))->toThrow(UnknownCurrencyException::class);

    expect(MoneyForm::display(4461303, 'ZZZ'))->toBe('44,613.03 ZZZ')
        ->and(MoneyForm::display(4461303, 'US'))->toBe('44,613.03 US');
});

it('renders a price row rather than 500ing when it carries a non-ISO currency', function () {
    $product = Product::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Bogus currency probe',
    ]);

    // ProductPrice::setUnitPriceAttribute multiplies by 100 on save, so 25
    // stores 2500 cents. 'ZZZ' is what a three-character free-text currency
    // field lets a user save.
    $price = ProductPrice::create([
        'external_id' => (string) Str::uuid(),
        'product_id' => $product->id,
        'unit_price' => 25,
        'currency' => 'ZZZ',
        'default' => true,
    ]);

    livewire(ProductPricesRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => ViewProduct::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$price])
        ->assertSee('25.00 ZZZ');
});
