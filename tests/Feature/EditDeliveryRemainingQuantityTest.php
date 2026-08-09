<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Delivery;
use VentureDrake\LaravelCrm\Models\DeliveryProduct;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\OrderProduct;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrmFilament\Resources\Deliveries\Pages\EditDelivery;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * A delivery line must not be counted against its own remaining quantity.
 *
 * DeliveryResource's quantity rule excludes the row being edited via a hidden
 * `delivery_product_id`, but EditDelivery's mutateFormDataBeforeFill() did not
 * hydrate it — so the row's own quantity was subtracted from the order line's
 * remainder before validating that same row against it. Any delivery covering
 * more than half its order line became permanently unsaveable, with the
 * self-contradicting "Quantity cannot be more than the 0 remaining".
 *
 * EditInvoice hydrates `invoice_line_id` for exactly this reason.
 */
beforeEach(function () {
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'Delivery Editor',
        'email' => 'delivery-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->assignRole('Owner');
    $this->actingAs($this->user->fresh());

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
        'price' => 10,
        'amount' => 100,
    ]);

    $this->delivery = Delivery::create([
        'external_id' => (string) Str::uuid(),
        'order_id' => $this->order->id,
    ]);

    // The whole order line, in one delivery — the case that broke.
    $this->deliveryProduct = DeliveryProduct::create([
        'external_id' => (string) Str::uuid(),
        'delivery_id' => $this->delivery->id,
        'order_product_id' => $this->orderProduct->id,
        'quantity' => 10,
    ]);
});

it('hydrates the line key so a row is not validated against itself', function () {
    $state = livewire(EditDelivery::class, ['record' => $this->delivery->external_id])
        ->assertFormSet(fn (array $state): array => $state)
        ->get('data.products');

    expect($state)->toHaveCount(1)
        ->and(reset($state)['delivery_product_id'])->toBe($this->deliveryProduct->id);
});

it('saves a fully-drawn delivery unchanged', function () {
    livewire(EditDelivery::class, ['record' => $this->delivery->external_id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((float) $this->deliveryProduct->fresh()->quantity)->toBe(10.0);
});

it('still rejects a quantity beyond what the order line has left', function () {
    $state = livewire(EditDelivery::class, ['record' => $this->delivery->external_id]);

    $products = $state->get('data.products');
    $key = array_key_first($products);
    $products[$key]['quantity'] = 11;

    $state->set('data.products', $products)
        ->call('save')
        ->assertHasFormErrors(["products.{$key}.quantity"]);
});
