<?php

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\PurchaseOrder;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\PurchaseOrderResource;

use function Pest\Livewire\livewire;

/**
 * Core 2.7.0 shows a soft-deleted parent order or contact as
 * "Name (deleted)" with no link, rather than a link to a page that 404s or a
 * blank. The PO keeps pointing at them: the document was made from / for them.
 */
beforeEach(function () {
    actingAsCrmAdmin();

    $this->order = Order::create(['external_id' => (string) Str::uuid()]);
    $this->person = Person::create(['external_id' => (string) Str::uuid(), 'first_name' => 'Sam', 'last_name' => 'Supplier']);
    $this->organization = Organization::create(['external_id' => (string) Str::uuid(), 'name' => 'Supplier Co']);

    $this->purchaseOrder = PurchaseOrder::create([
        'external_id' => (string) Str::uuid(),
        'order_id' => $this->order->id,
        'person_id' => $this->person->id,
        'organization_id' => $this->organization->id,
    ]);
});

/**
 * @return array<string, TextEntry>
 */
function purchaseOrderInfolistEntries(PurchaseOrder $record): array
{
    $schema = PurchaseOrderResource::infolist(
        Schema::make(livewire(ListPurchaseOrders::class)->instance())->record($record),
    );

    $entries = [];

    foreach ($schema->getFlatComponents(withActions: false, withHidden: true) as $component) {
        if ($component instanceof TextEntry) {
            $entries[$component->getName()] = $component;
        }
    }

    return $entries;
}

function purchaseOrderOrderColumn(PurchaseOrder $record)
{
    return livewire(ListPurchaseOrders::class)->instance()->getTable()->getColumns()['order.order_id']->record($record);
}

it('links a live parent order and contacts', function () {
    $po = $this->purchaseOrder->fresh();
    $column = purchaseOrderOrderColumn($po);
    $entries = purchaseOrderInfolistEntries($po);

    expect($column->formatState($column->getState()))->toBe($this->order->fresh()->order_id)
        ->and($column->getUrl())->toContain($this->order->external_id)
        ->and($entries['order.order_id']->getUrl())->toContain($this->order->external_id)
        ->and($entries['personWithTrashed.name']->getState())->toBe('Sam Supplier')
        ->and($entries['personWithTrashed.name']->getUrl())->toContain($this->person->external_id)
        ->and($entries['organizationWithTrashed.name']->getState())->toBe('Supplier Co')
        ->and($entries['organizationWithTrashed.name']->getUrl())->toContain($this->organization->external_id);
});

it('shows a deleted parent order as "No. (deleted)" with no link', function () {
    $this->order->delete();

    $po = $this->purchaseOrder->fresh();
    $column = purchaseOrderOrderColumn($po);
    $entry = purchaseOrderInfolistEntries($po)['order.order_id'];

    expect($column->formatState($column->getState()))->toBe($this->order->fresh()->order_id . ' (deleted)')
        ->and($column->getUrl())->toBeNull()
        ->and($entry->formatState($entry->getState()))->toBe($this->order->fresh()->order_id . ' (deleted)')
        ->and($entry->getUrl())->toBeNull();
});

it('shows deleted contacts as "Name (deleted)" with no link', function () {
    $this->person->delete();
    $this->organization->delete();

    $entries = purchaseOrderInfolistEntries($this->purchaseOrder->fresh());

    expect($entries['personWithTrashed.name']->getState())->toBe('Sam Supplier (deleted)')
        ->and($entries['personWithTrashed.name']->getUrl())->toBeNull()
        ->and($entries['organizationWithTrashed.name']->getState())->toBe('Supplier Co (deleted)')
        ->and($entries['organizationWithTrashed.name']->getUrl())->toBeNull();
});
