<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Delivery;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrmFilament\Resources\Deliveries\Pages\ListDeliveries;

use function Pest\Livewire\livewire;

/**
 * Core 2.7.0's RecordSearch on the delivery list: the delivery number however
 * it is typed, and the order's reference (a delivery has none of its own),
 * matched literally, as core's DeliveryIndex does.
 */
beforeEach(function () {
    actingAsCrmAdmin();

    $this->match = deliverySearchFixture(1001, 'Crate 50% full');
    $this->other = deliverySearchFixture(2002, 'Crate 500 units');
});

/**
 * The observers number a record on create, so the number is set afterwards.
 */
function deliverySearchFixture(int $number, string $reference): Delivery
{
    $order = Order::create(['external_id' => (string) Str::uuid(), 'reference' => $reference]);

    $delivery = Delivery::create(['external_id' => (string) Str::uuid(), 'order_id' => $order->id]);
    $delivery->forceFill(['prefix' => 'DEL-', 'number' => $number, 'delivery_id' => 'DEL-' . $number])->saveQuietly();

    return $delivery;
}

it('finds a delivery by its number however it is typed', function (string $term) {
    livewire(ListDeliveries::class)
        ->searchTable($term)
        ->assertCanSeeTableRecords([$this->match])
        ->assertCanNotSeeTableRecords([$this->other]);
})->with(['full number' => 'DEL-1001', 'digits only' => '1001', 'lower case' => 'del-1001']);

it('finds a delivery by its order\'s reference', function () {
    livewire(ListDeliveries::class)
        ->searchTable('500 units')
        ->assertCanSeeTableRecords([$this->other])
        ->assertCanNotSeeTableRecords([$this->match]);
});

it('matches the order reference literally, so % is not a wildcard', function () {
    livewire(ListDeliveries::class)
        ->searchTable('50%')
        ->assertCanSeeTableRecords([$this->match])
        ->assertCanNotSeeTableRecords([$this->other]);
});

it('still finds a delivery whose order was soft deleted by its reference', function () {
    $this->other->order->delete();

    livewire(ListDeliveries::class)
        ->searchTable('500 units')
        ->assertCanSeeTableRecords([$this->other->fresh()]);
});
