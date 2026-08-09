<?php

use Filament\Forms\Components\Hidden;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\InvoiceLine;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\OrderProduct;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\LineItemsRepeater;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\EditInvoice;
use VentureDrake\LaravelCrmFilament\Support\RemainingQuantity;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * EditInvoice hydrated `order_product_id` into each repeater row, but the
 * repeater had no field for it — so every invoice edit re-submitted the key
 * absent and InvoiceService's `?? null` nulled the order linkage. Nothing
 * errored; the invoice just quietly stopped drawing down its order line, and
 * every outstanding-quantity calculation from then on was wrong.
 */
beforeEach(function () {
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'Linkage Tester',
        'email' => 'linkage-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->assignRole('Owner');
    $this->actingAs($this->user->fresh());

    Setting::updateOrCreate(['name' => 'currency'], ['value' => 'USD']);

    $this->product = Product::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Widget',
        'active' => true,
    ]);

    $this->order = Order::create(['external_id' => (string) Str::uuid()]);

    $this->orderProduct = OrderProduct::create([
        'external_id' => (string) Str::uuid(),
        'order_id' => $this->order->id,
        'product_id' => $this->product->id,
        'quantity' => 10,
        'price' => 1000,
        'amount' => 10000,
    ]);
});

it('exposes order_product_id as a hidden field only on the drawdown forms', function () {
    $withLinkage = LineItemsRepeater::products(
        fkColumn: 'invoice_line_id',
        priceField: 'unit_price',
        withOrderProductId: true,
    );

    $withoutLinkage = LineItemsRepeater::products('quote_product_id', 'unit_price');

    $names = fn ($repeater) => collect(hiddenFieldNames($repeater));

    expect($names($withLinkage))->toContain('order_product_id')
        // A quote line draws nothing down, so carrying the key would be noise.
        ->and($names($withoutLinkage))->not->toContain('order_product_id');
});

/**
 * @return array<int, string>
 */
function hiddenFieldNames($repeater): array
{
    $ref = new ReflectionProperty($repeater, 'childComponents');
    $ref->setAccessible(true);
    $children = $ref->getValue($repeater);

    return collect($children['default'] ?? $children)
        ->filter(fn ($component) => $component instanceof Hidden)
        ->map(fn ($component) => $component->getName())
        ->values()
        ->all();
}

it('keeps the order linkage through an invoice edit that changes nothing else', function () {
    $invoice = Invoice::create([
        'external_id' => (string) Str::uuid(),
        'order_id' => $this->order->id,
    ]);

    $line = InvoiceLine::create([
        'external_id' => (string) Str::uuid(),
        'invoice_id' => $invoice->id,
        'order_product_id' => $this->orderProduct->id,
        'product_id' => $this->product->id,
        'quantity' => 6,
        'price' => 1000,
        'amount' => 6000,
    ]);

    // Before: the line draws 6 against the order, leaving 4.
    expect(RemainingQuantity::forOrderProduct($this->orderProduct, InvoiceLine::class, 'invoice'))
        ->toBe(4.0);

    livewire(EditInvoice::class, ['record' => $invoice->external_id])
        ->fillForm(['reference' => 'Touched'])
        ->call('save')
        ->assertHasNoFormErrors();

    $invoice->refresh();

    expect($invoice->reference)->toBe('Touched');

    $reloaded = $invoice->invoiceLines()->first();

    expect($reloaded)->not->toBeNull()
        ->and($reloaded->order_product_id)->toBe($this->orderProduct->id);

    // …and the drawdown maths still agrees, which is the thing that actually
    // went wrong when the linkage was dropped.
    expect(RemainingQuantity::forOrderProduct($this->orderProduct->fresh(), InvoiceLine::class, 'invoice'))
        ->toBe(4.0);
});
