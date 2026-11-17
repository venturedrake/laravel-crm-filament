<?php

use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Delivery;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\EditOrder;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\ListOrders;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\ViewOrder;

use function Pest\Livewire\livewire;

/**
 * Core 2.7.0 (#102): OrderPolicy::delete refuses an order that invoices,
 * deliveries or purchase orders still point at, with
 * Order::deleteBlockedReason() as the message. The panel's Delete buttons
 * stay visible but disabled, with that reason as the tooltip.
 */
beforeEach(function () {
    $this->user = actingAsCrmAdmin();

    $this->order = Order::create(['external_id' => (string) Str::uuid(), 'reference' => 'ORD-DELETE']);
});

function invoiceOrder(Order $order): Invoice
{
    return Invoice::create(['external_id' => (string) Str::uuid(), 'order_id' => $order->id]);
}

it('disables the table row Delete of an invoiced order with core\'s reason', function () {
    invoiceOrder($this->order);

    $page = livewire(ListOrders::class)
        ->assertActionVisible(TestAction::make('delete')->table($this->order))
        ->assertActionDisabled(TestAction::make('delete')->table($this->order));

    $action = $page->instance()->getTable()->getAction('delete')->record($this->order);

    expect($action->getTooltip())->toBe("This order has invoices and can't be deleted");
});

it('disables the edit page Delete with the reason and does not delete', function () {
    invoiceOrder($this->order);

    $page = livewire(EditOrder::class, ['record' => $this->order->external_id])
        ->assertActionVisible('delete')
        ->assertActionDisabled('delete');

    expect($page->instance()->getAction('delete')->getTooltip())
        ->toBe("This order has invoices and can't be deleted");

    $page->callAction('delete');

    expect(Order::find($this->order->id))->not->toBeNull();
});

it('gives the delivered reason when the order only has deliveries', function () {
    Delivery::create(['external_id' => (string) Str::uuid(), 'order_id' => $this->order->id]);

    $page = livewire(EditOrder::class, ['record' => $this->order->external_id])
        ->assertActionDisabled('delete');

    expect($page->instance()->getAction('delete')->getTooltip())
        ->toBe("This order has deliveries and can't be deleted");
});

it('ignores a soft-deleted invoice, so the order can be deleted again', function () {
    invoiceOrder($this->order)->delete();

    livewire(EditOrder::class, ['record' => $this->order->external_id])
        ->assertActionEnabled('delete')
        ->callAction('delete');

    expect(Order::find($this->order->id))->toBeNull();
});

it('deletes an order with nothing pointing at it from the table', function () {
    livewire(ListOrders::class)
        ->assertActionEnabled(TestAction::make('delete')->table($this->order))
        ->callAction(TestAction::make('delete')->table($this->order));

    expect(Order::find($this->order->id))->toBeNull();
});

it('puts the authorization tooltip on the view page Delete too', function () {
    $page = (new ReflectionClass(ViewOrder::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(ViewOrder::class, 'getHeaderActions');
    $method->setAccessible(true);

    $delete = collect($method->invoke($page))->first(fn ($action) => $action instanceof DeleteAction);

    expect($delete)->not->toBeNull()
        ->and($delete->hasAuthorizationTooltip())->toBeTrue();
});
