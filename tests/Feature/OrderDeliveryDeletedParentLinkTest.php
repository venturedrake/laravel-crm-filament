<?php

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Delivery;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrmFilament\Resources\Deliveries\DeliveryResource;
use VentureDrake\LaravelCrmFilament\Resources\Deliveries\Pages\ListDeliveries;
use VentureDrake\LaravelCrmFilament\Resources\Orders\OrderResource;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\ListOrders;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\QuoteResource;

use function Pest\Livewire\livewire;

/**
 * Core 2.7.0's order `quote` and delivery `order` relations include a
 * soft-deleted parent, shown as "No. (deleted)" with no link to a page that
 * 404s. Contacts read through the *WithTrashed relations the same way.
 */
beforeEach(function () {
    actingAsCrmAdmin();

    $this->quote = Quote::create(['external_id' => (string) Str::uuid(), 'title' => 'Parent quote']);
    $this->quote->forceFill(['quote_id' => 'QU-1001'])->saveQuietly();

    $this->person = Person::create(['external_id' => (string) Str::uuid(), 'first_name' => 'Grace', 'last_name' => 'Hopper']);
    $this->organization = Organization::create(['external_id' => (string) Str::uuid(), 'name' => 'Navy']);

    $this->order = Order::create([
        'external_id' => (string) Str::uuid(),
        'quote_id' => $this->quote->id,
        'person_id' => $this->person->id,
        'organization_id' => $this->organization->id,
        'reference' => 'ORDER-REFERENCE-140',
    ]);
    $this->order->forceFill(['order_id' => 'ORD-2001'])->saveQuietly();

    $this->delivery = Delivery::create(['external_id' => (string) Str::uuid(), 'order_id' => $this->order->id]);
});

/**
 * @return array{0: mixed, 1: ?string}
 */
function listedParentCell(string $page, string $column, $record): array
{
    $column = livewire($page)->instance()->getTable()->getColumn($column)->record($record);

    return [$column->formatState($column->getState()), $column->getUrl()];
}

/**
 * An infolist entry as the view page builds it for $record.
 *
 * @return array{0: mixed, 1: ?string}
 */
function viewedEntry(string $resource, string $name, Model $record): array
{
    // Any resource page will do as the schema's Livewire component; the view
    // page itself trips the harness on crm-view-tabs.blade.php.
    $schema = Schema::make(livewire(ListOrders::class)->instance());
    $schema->record($record);
    $resource::infolist($schema);

    /** @var TextEntry $entry */
    $entry = $schema->getComponent(
        fn ($component) => $component instanceof TextEntry && $component->getName() === $name,
        withHidden: true,
    );

    return [$entry->formatState($entry->getState()), $entry->getUrl()];
}

it('links an order to its live quote', function () {
    [$label, $url] = listedParentCell(ListOrders::class, 'quote.quote_id', $this->order->fresh());

    expect($label)->toBe('QU-1001')
        ->and($url)->toContain($this->quote->external_id);
});

it('shows a soft-deleted quote as "(deleted)" with no link on the order list', function () {
    $this->quote->delete();

    [$label, $url] = listedParentCell(ListOrders::class, 'quote.quote_id', $this->order->fresh());

    expect($label)->toBe('QU-1001 (deleted)')
        ->and($url)->toBeNull();
});

it('shows a soft-deleted order as "(deleted)" with no link on the delivery list', function () {
    $this->order->delete();

    [$label, $url] = listedParentCell(ListDeliveries::class, 'order.order_id', $this->delivery->fresh());

    expect($label)->toBe('ORD-2001 (deleted)')
        ->and($url)->toBeNull();
});

it('keeps the order reference on a delivery whose order was soft deleted', function () {
    $this->order->delete();

    livewire(ListDeliveries::class)
        ->assertTableColumnStateSet('order.reference', 'ORDER-REFERENCE-140', $this->delivery->fresh());
});

it('labels the parent-document and contact helpers "(deleted)" only when trashed', function () {
    expect(OrderResource::parentLabel('QU-1001', $this->quote))->toBe('QU-1001')
        ->and(OrderResource::parentLabel(null, $this->quote))->toBeNull()
        ->and(OrderResource::trashedAwareLabel('Grace Hopper', $this->person))->toBe('Grace Hopper');

    $this->quote->delete();
    $this->person->delete();

    expect(OrderResource::parentLabel('QU-1001', $this->quote->fresh()))->toBe('QU-1001 (deleted)')
        ->and(OrderResource::trashedAwareLabel('Grace Hopper', Person::withTrashed()->find($this->person->id)))
        ->toBe('Grace Hopper (deleted)');
});

it('shows the soft-deleted quote as "(deleted)" with no link on the order view', function () {
    expect(viewedEntry(OrderResource::class, 'quote.quote_id', $this->order->fresh()))
        ->toBe(['QU-1001', QuoteResource::getUrl('view', ['record' => $this->quote])]);

    $this->quote->delete();

    expect(viewedEntry(OrderResource::class, 'quote.quote_id', $this->order->fresh()))
        ->toBe(['QU-1001 (deleted)', null]);
});

it('shows the soft-deleted order as "(deleted)" with no link on the delivery view', function () {
    $this->order->delete();

    expect(viewedEntry(DeliveryResource::class, 'order.order_id', $this->delivery->fresh()))
        ->toBe(['ORD-2001 (deleted)', null])
        ->and(viewedEntry(DeliveryResource::class, 'order.reference', $this->delivery->fresh())[0])
        ->toBe('ORDER-REFERENCE-140');
});

it('shows deleted order contacts as "Name (deleted)" with no link', function () {
    [$person, $personUrl] = viewedEntry(OrderResource::class, 'personWithTrashed.name', $this->order->fresh());
    expect($person)->toBe('Grace Hopper')->and($personUrl)->toContain($this->person->external_id);

    $this->person->delete();
    $this->organization->delete();

    expect(viewedEntry(OrderResource::class, 'personWithTrashed.name', $this->order->fresh()))
        ->toBe(['Grace Hopper (deleted)', null])
        ->and(viewedEntry(OrderResource::class, 'organizationWithTrashed.name', $this->order->fresh()))
        ->toBe(['Navy (deleted)', null])
        // A delivery's contact is its order's.
        ->and(viewedEntry(DeliveryResource::class, 'order.person.name', $this->delivery->fresh()))
        ->toBe(['Grace Hopper (deleted)', null]);
});
