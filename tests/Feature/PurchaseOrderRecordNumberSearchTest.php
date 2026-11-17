<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\PurchaseOrder;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;

use function Pest\Livewire\livewire;

/**
 * The purchase-order list searches the number as core 2.7.0's RecordSearch
 * does: the stored `purchase_order_id`, or its prefix and number parts (each
 * PO keeps the prefix it was created with), and the reference literally, so
 * `%` finds a percent sign rather than every row.
 */
beforeEach(function () {
    actingAsCrmAdmin();

    app('laravel-crm.settings')->set('purchase_order_prefix', 'PO-');
    app('laravel-crm.settings')->forgetCache();

    $this->stored = numberedPurchaseOrder('Steel beams', ['purchase_order_id' => 'PO-1001', 'prefix' => 'PO-', 'number' => 1001]);

    // Made before the prefix existed: no stored id or prefix, so it answers
    // to the current prefix and its number.
    $this->parts = numberedPurchaseOrder('50% deposit', ['purchase_order_id' => null, 'prefix' => null, 'number' => 2002]);

    $this->other = numberedPurchaseOrder('Timber', ['purchase_order_id' => 'PO-3003', 'prefix' => 'PO-', 'number' => 3003]);
});

/**
 * The model numbers a new PO itself, so the numbering columns are written
 * afterwards.
 */
function numberedPurchaseOrder(string $reference, array $numbering): PurchaseOrder
{
    $purchaseOrder = PurchaseOrder::create(['external_id' => (string) Str::uuid(), 'reference' => $reference]);

    PurchaseOrder::query()->whereKey($purchaseOrder->getKey())->toBase()->update($numbering);

    return $purchaseOrder->fresh();
}

it('finds a purchase order by its full number', function () {
    livewire(ListPurchaseOrders::class)
        ->searchTable('PO-1001')
        ->assertCanSeeTableRecords([$this->stored])
        ->assertCanNotSeeTableRecords([$this->parts, $this->other]);
});

it('finds a purchase order by the digits alone', function () {
    livewire(ListPurchaseOrders::class)
        ->searchTable('2002')
        ->assertCanSeeTableRecords([$this->parts])
        ->assertCanNotSeeTableRecords([$this->stored, $this->other]);
});

it('finds a purchase order with no stored id by the current prefix and its number', function () {
    livewire(ListPurchaseOrders::class)
        ->searchTable('po-2002')
        ->assertCanSeeTableRecords([$this->parts])
        ->assertCanNotSeeTableRecords([$this->stored, $this->other]);
});

it('searches the reference case-insensitively', function () {
    livewire(ListPurchaseOrders::class)
        ->searchTable('steel')
        ->assertCanSeeTableRecords([$this->stored])
        ->assertCanNotSeeTableRecords([$this->parts, $this->other]);
});

it('matches a percent sign literally', function () {
    livewire(ListPurchaseOrders::class)
        ->searchTable('%')
        ->assertCanSeeTableRecords([$this->parts])
        ->assertCanNotSeeTableRecords([$this->stored, $this->other]);
});
