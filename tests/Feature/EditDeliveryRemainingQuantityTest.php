<?php

use Filament\Forms\Components\Repeater;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Delivery;
use VentureDrake\LaravelCrm\Models\DeliveryProduct;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\OrderProduct;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrmFilament\Resources\Deliveries\Pages\CreateDelivery;
use VentureDrake\LaravelCrmFilament\Resources\Deliveries\Pages\EditDelivery;
use VentureDrake\LaravelCrmFilament\Support\RemainingQuantity;
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

it('rejects a raised quantity with core\'s quantity_exceeds_remaining message', function () {
    $state = livewire(EditDelivery::class, ['record' => $this->delivery->external_id]);

    $products = $state->get('data.products');
    $key = array_key_first($products);
    $products[$key]['quantity'] = 11;

    $state->set('data.products', $products)
        ->call('save')
        ->assertHasFormErrors(["products.{$key}.quantity" => [
            ucfirst(__('laravel-crm::lang.quantity_exceeds_remaining', ['remaining' => '10'])),
        ]]);
});

it('saves a delivery whose order line was reduced after it was made', function () {
    // Core 2.7.0's owner exemption: a saved line of this delivery left at or
    // below its saved quantity is not re-capped, though only 5 are now left.
    $this->orderProduct->update(['quantity' => 5]);

    livewire(EditDelivery::class, ['record' => $this->delivery->external_id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((float) $this->deliveryProduct->fresh()->quantity)->toBe(10.0);
});

it('lowers a saved line below the reduced order line', function () {
    $this->orderProduct->update(['quantity' => 5]);

    $state = livewire(EditDelivery::class, ['record' => $this->delivery->external_id]);

    $products = $state->get('data.products');
    $key = array_key_first($products);
    $products[$key]['quantity'] = 8;

    $state->set('data.products', $products)
        ->call('save')
        ->assertHasNoFormErrors();

    expect((float) $this->deliveryProduct->fresh()->quantity)->toBe(8.0);
});

it('gives no exemption to a row key forged onto another delivery\'s line', function () {
    $other = Delivery::create(['external_id' => (string) Str::uuid(), 'order_id' => $this->order->id]);
    $otherLine = DeliveryProduct::create([
        'external_id' => (string) Str::uuid(),
        'delivery_id' => $other->id,
        'order_product_id' => $this->orderProduct->id,
        'quantity' => 4,
    ]);
    $this->deliveryProduct->update(['quantity' => 2]);

    $rule = RemainingQuantity::notRaised(
        ['order_product_id' => $this->orderProduct->id, 'delivery_product_id' => $otherLine->id],
        4,
        DeliveryProduct::class,
        'delivery_product_id',
        ['delivery_id' => $this->delivery->id],
    );

    expect($rule)->toBeFalse()
        ->and(RemainingQuantity::notRaised(
            ['order_product_id' => $this->orderProduct->id, 'delivery_product_id' => $this->deliveryProduct->id],
            2,
            DeliveryProduct::class,
            'delivery_product_id',
            ['delivery_id' => $this->delivery->id],
        ))->toBeTrue()
        // No owner on a create form, so no exemption.
        ->and(RemainingQuantity::notRaised(
            ['order_product_id' => $this->orderProduct->id, 'delivery_product_id' => $this->deliveryProduct->id],
            2,
            DeliveryProduct::class,
            'delivery_product_id',
            null,
        ))->toBeFalse();
});

it('offers no add or remove on edit, and keeps each row on its order line', function () {
    // DeliveryService::update() ignores added and removed rows and the
    // order line; it only changes the quantity of the lines it has.
    livewire(EditDelivery::class, ['record' => $this->delivery->external_id])
        ->assertFormFieldExists('products', fn (Repeater $repeater): bool => ! $repeater->isAddable() && ! $repeater->isDeletable())
        ->assertFormFieldDisabled('order_id');

    livewire(CreateDelivery::class)
        ->assertFormFieldExists('products', fn (Repeater $repeater): bool => $repeater->isAddable() && $repeater->isDeletable())
        ->assertFormFieldEnabled('order_id');
});

it('disables the order line select on edit', function () {
    $component = livewire(EditDelivery::class, ['record' => $this->delivery->external_id]);
    $key = array_key_first($component->get('data.products'));

    $component->assertFormFieldDisabled("products.{$key}.order_product_id");
});
