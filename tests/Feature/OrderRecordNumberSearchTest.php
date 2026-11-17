<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\ListOrders;

use function Pest\Livewire\livewire;

/**
 * Core 2.7.0's RecordSearch on the order list: "ORD-1001", "1001" and
 * "ord-1001" all find the order, and the reference is matched literally.
 */
beforeEach(function () {
    actingAsCrmAdmin();

    Setting::updateOrCreate(['name' => 'order_prefix'], ['external_id' => (string) Str::uuid(), 'value' => 'ORD-']);

    $this->match = orderSearchFixture(1001, 'PO 50% off');
    $this->other = orderSearchFixture(2002, 'PO 500 units');
});

/**
 * OrderObserver numbers an order on create, so the number is set afterwards.
 */
function orderSearchFixture(int $number, string $reference): Order
{
    $order = Order::create(['external_id' => (string) Str::uuid(), 'reference' => $reference]);
    $order->forceFill(['prefix' => 'ORD-', 'number' => $number, 'order_id' => 'ORD-' . $number])->saveQuietly();

    return $order;
}

it('finds an order by its number however it is typed', function (string $term) {
    livewire(ListOrders::class)
        ->searchTable($term)
        ->assertCanSeeTableRecords([$this->match])
        ->assertCanNotSeeTableRecords([$this->other]);
})->with(['full number' => 'ORD-1001', 'digits only' => '1001', 'lower case' => 'ord-1001']);

it('matches the reference literally, so % is not a wildcard', function () {
    livewire(ListOrders::class)
        ->searchTable('50%')
        ->assertCanSeeTableRecords([$this->match])
        ->assertCanNotSeeTableRecords([$this->other]);
});

it('finds an order by part of its reference', function () {
    livewire(ListOrders::class)
        ->searchTable('500 units')
        ->assertCanSeeTableRecords([$this->other])
        ->assertCanNotSeeTableRecords([$this->match]);
});
