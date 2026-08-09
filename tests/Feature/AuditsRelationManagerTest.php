<?php

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Ramsey\Uuid\Uuid;
use VentureDrake\LaravelCrm\Models\Deal;
use VentureDrake\LaravelCrm\Models\Delivery;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\PurchaseOrder;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrmFilament\LaravelCrmFilamentServiceProvider;
use VentureDrake\LaravelCrmFilament\Models\Audit;
use VentureDrake\LaravelCrmFilament\RelationManagers\AuditsRelationManager;
use VentureDrake\LaravelCrmFilament\Resources\Deals\DealResource;
use VentureDrake\LaravelCrmFilament\Resources\Deliveries\DeliveryResource;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\InvoiceResource;
use VentureDrake\LaravelCrmFilament\Resources\Orders\OrderResource;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\OrganizationResource;
use VentureDrake\LaravelCrmFilament\Resources\People\PersonResource;
use VentureDrake\LaravelCrmFilament\Resources\Products\ProductResource;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\PurchaseOrderResource;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\QuoteResource;

dataset('auditedResources', [
    'deal' => [DealResource::class, Deal::class],
    'quote' => [QuoteResource::class, Quote::class],
    'order' => [OrderResource::class, Order::class],
    'invoice' => [InvoiceResource::class, Invoice::class],
    'delivery' => [DeliveryResource::class, Delivery::class],
    'purchase_order' => [PurchaseOrderResource::class, PurchaseOrder::class],
    'person' => [PersonResource::class, Person::class],
    'organization' => [OrganizationResource::class, Organization::class],
    'product' => [ProductResource::class, Product::class],
]);

it('is not registered on the primary resources — the History tab was removed', function (string $resource) {
    // The audit trail is still recorded and the relation manager still ships
    // (FeatureResource uses it); it was deliberately dropped from the primary
    // resources' show pages along with the History tab. This assertion is
    // inverted rather than deleted so re-adding it is a conscious act.
    expect($resource::getRelations())->not->toContain(AuditsRelationManager::class);
})->with('auditedResources');

it('declares the relationship as audits', function () {
    $reflection = new ReflectionClass(AuditsRelationManager::class);
    $property = $reflection->getProperty('relationship');

    expect($property->getValue())->toBe('audits');
});

it('is read-only — no create, edit, or delete actions', function () {
    $rm = AuditsRelationManager::class;

    // isReadOnly() returns true so Filament's RelationManager hides CRUD UI.
    $instance = (new ReflectionClass($rm))->newInstanceWithoutConstructor();
    expect($instance->isReadOnly())->toBeTrue();

    // The table() builder registers no header / record / toolbar actions.
    $source = file_get_contents((new ReflectionClass($rm))->getFileName());
    expect($source)->toContain('->headerActions([])');
    expect($source)->toContain('->recordActions([])');
    expect($source)->toContain('->toolbarActions([])');
});

it('paginates the audit timeline', function () {
    $source = file_get_contents((new ReflectionClass(AuditsRelationManager::class))->getFileName());

    expect($source)->toContain('->paginated([10, 25, 50, 100])');
    expect($source)->toContain('->defaultPaginationPageOption(25)');
});

it('resolves an audits() morphMany relation on every primary model', function (string $resource, string $modelClass) {
    $instance = new $modelClass;
    $relation = $instance->audits();

    expect($relation)->toBeInstanceOf(MorphMany::class);
    expect($relation->getRelated())->toBeInstanceOf(Audit::class);
})->with('auditedResources');

it('lists models considered auditable by the plugin', function () {
    $auditable = LaravelCrmFilamentServiceProvider::auditableModels();

    expect($auditable)->toContain(Lead::class, Deal::class, Quote::class, Order::class, Invoice::class)
        ->and($auditable)->toContain(Delivery::class, PurchaseOrder::class, Person::class, Organization::class, Product::class)
        ->and($auditable)->toContain('VentureDrake\\LaravelCrm\\Models\\Feature')
        ->and($auditable)->toContain('VentureDrake\\LaravelCrm\\Models\\Monitor');
    // Feature was added in US-003 + Monitor in US-006 (features+monitors series) so the list is now 12.
    expect(count($auditable))->toBe(12);
});

it('filters the audit feed by auditable_type and auditable_id', function () {
    $leadA = Lead::create([
        'external_id' => (string) Uuid::uuid4(),
        'title' => 'Lead A',
    ]);
    $leadB = Lead::create([
        'external_id' => (string) Uuid::uuid4(),
        'title' => 'Lead B',
    ]);

    Audit::create([
        'event' => 'created',
        'auditable_type' => Lead::class,
        'auditable_id' => $leadA->id,
        'old_values' => [],
        'new_values' => ['title' => 'Lead A'],
    ]);
    Audit::create([
        'event' => 'updated',
        'auditable_type' => Lead::class,
        'auditable_id' => $leadA->id,
        'old_values' => ['title' => 'Lead A'],
        'new_values' => ['title' => 'Lead A!'],
    ]);
    Audit::create([
        'event' => 'created',
        'auditable_type' => Lead::class,
        'auditable_id' => $leadB->id,
        'old_values' => [],
        'new_values' => ['title' => 'Lead B'],
    ]);

    expect($leadA->audits()->count())->toBe(2)
        ->and($leadB->audits()->count())->toBe(1);

    $eventsForA = $leadA->audits()->pluck('event')->all();
    expect($eventsForA)->toEqualCanonicalizing(['created', 'updated']);
});

it('renders changed fields as a before -> after summary', function () {
    $rm = new ReflectionClass(AuditsRelationManager::class);
    $method = $rm->getMethod('formatChanges');
    $method->setAccessible(true);

    $audit = new Audit([
        'event' => 'updated',
        'old_values' => ['title' => 'Old', 'amount' => 100],
        'new_values' => ['title' => 'New', 'amount' => 200],
    ]);

    $html = $method->invoke(null, $audit);

    expect($html)->toContain('title')
        ->and($html)->toContain('Old')
        ->and($html)->toContain('New')
        ->and($html)->toContain('amount')
        ->and($html)->toContain('100')
        ->and($html)->toContain('200');
});

it('shows an em-dash placeholder when no fields changed', function () {
    $rm = new ReflectionClass(AuditsRelationManager::class);
    $method = $rm->getMethod('formatChanges');
    $method->setAccessible(true);

    $audit = new Audit([
        'event' => 'restored',
        'old_values' => [],
        'new_values' => [],
    ]);

    expect($method->invoke(null, $audit))->toBe('—');
});

it('declares the History title via labels.audit.history', function () {
    $file = file_get_contents((new ReflectionClass(AuditsRelationManager::class))->getFileName());
    expect($file)->toContain("'laravel-crm-filament::labels.audit.history'");
});
