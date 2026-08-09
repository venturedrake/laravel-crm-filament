<?php

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\PurchaseOrder;
use VentureDrake\LaravelCrm\Models\XeroPurchaseOrder;
use VentureDrake\LaravelCrmFilament\Concerns\HasPrimaryBulkActions;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\PurchaseOrderResource;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * The Edit and Delete row actions, resolved by type.
 *
 * @return array{0: EditAction, 1: DeleteAction}
 */
function purchaseOrderEditAndDeleteRowActions(): array
{
    $actions = collect(livewire(ListPurchaseOrders::class)->instance()->getTable()->getRecordActions());

    $edit = $actions->first(fn ($action) => $action instanceof EditAction);
    $delete = $actions->first(fn ($action) => $action instanceof DeleteAction);

    expect($edit)->not->toBeNull();
    expect($delete)->not->toBeNull();

    return [$edit, $delete];
}

beforeEach(function () {
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'PO Parity Tester',
        'email' => 'po-parity-' . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->assignRole('Admin');
    $this->actingAs($this->user);
});

it('PurchaseOrderResource uses the HasPrimaryBulkActions trait', function () {
    expect(class_uses_recursive(PurchaseOrderResource::class))
        ->toContain(HasPrimaryBulkActions::class);
});

it('table registers the parity column order', function () {
    $columns = livewire(ListPurchaseOrders::class)->instance()->getTable()->getColumns();
    $names = array_values(array_map(fn ($c) => $c->getName(), $columns));

    expect($names)->toBe([
        'created_at',
        'purchase_order_id',
        'reference',
        'order.order_id',
        'person.name',
        'organization.name',
        'issue_date',
        'delivery_date',
        'delivery_type',
        'total',
        'sent',
    ]);
});

it('created_at uses ->since() and sent renders as a boolean icon column', function () {
    $columns = livewire(ListPurchaseOrders::class)->instance()->getTable()->getColumns();

    expect($columns['sent'])->toBeInstanceOf(IconColumn::class);

    $source = file_get_contents(__DIR__ . '/../../src/Resources/PurchaseOrders/PurchaseOrderResource.php');
    expect($source)->toContain('->since()');
    expect($source)->toContain("IconColumn::make('sent')");
    expect($source)->toContain('->boolean()');
});

it('Order column resolves to a URL containing the parent order external_id', function () {
    $order = Order::create([
        'external_id' => (string) Str::uuid(),
        'reference' => 'ORD-FOR-PO',
    ]);
    $order->refresh();

    $po = PurchaseOrder::create([
        'external_id' => (string) Str::uuid(),
        'order_id' => $order->getKey(),
    ]);

    $columns = livewire(ListPurchaseOrders::class)->instance()->getTable()->getColumns();
    $orderColumn = $columns['order.order_id'];

    $url = $orderColumn->record($po)->getUrl();

    expect($url)->not->toBeNull();
    expect($url)->toContain($order->external_id);
});

it('Order column URL is null-safe when the purchase order has no parent order', function () {
    $po = PurchaseOrder::create([
        'external_id' => (string) Str::uuid(),
    ]);

    $columns = livewire(ListPurchaseOrders::class)->instance()->getTable()->getColumns();
    $orderColumn = $columns['order.order_id'];

    expect($orderColumn->record($po)->getUrl())->toBeNull();
});

it('registers owner and labels drawer filters with the expected config', function () {
    $filters = livewire(ListPurchaseOrders::class)->instance()->getTable()->getFilters();

    $byName = [];
    foreach ($filters as $filter) {
        $byName[$filter->getName()] = $filter;
    }

    expect(array_keys($byName))->toContain('user_owner_id', 'labels');

    /** @var SelectFilter $owner */
    $owner = $byName['user_owner_id'];
    /** @var SelectFilter $labels */
    $labels = $byName['labels'];

    expect($owner->isMultiple())->toBeTrue();
    expect($labels->isMultiple())->toBeTrue();
    expect($owner->getRelationshipName())->toBe('ownerUser');
    expect($labels->getRelationshipName())->toBe('labels');
});

it('row actions are buttons in View, Edit, Delete order with Delete confirmation', function () {
    $actions = array_values(livewire(ListPurchaseOrders::class)->instance()->getTable()->getRecordActions());

    // View / Edit / Delete close the row, after whatever document actions the
    // resource puts in front of them (download PDF, portal preview, …). The
    // ordering that matters is that the three CRUD actions come last, in this
    // order — not that they are the only ones.
    $actions = array_slice($actions, -3);

    expect($actions)->toHaveCount(3);
    expect($actions[0])->toBeInstanceOf(ViewAction::class);
    expect($actions[1])->toBeInstanceOf(EditAction::class);
    expect($actions[2])->toBeInstanceOf(DeleteAction::class);

    foreach ($actions as $action) {
        expect($action->isButton())->toBeTrue();
    }

    expect($actions[2]->isConfirmationRequired())->toBeTrue();
});

it('hides Edit and Delete row actions when the PO is linked to Xero', function () {
    $po = PurchaseOrder::create([
        'external_id' => (string) Str::uuid(),
    ]);

    XeroPurchaseOrder::create([
        'external_id' => (string) Str::uuid(),
        'purchase_order_id' => $po->getKey(),
        'xero_id' => (string) Str::uuid(),
    ]);

    // Resolved by type, not by position: the row now also carries Send,
    // Download PDF and Preview portal, so a positional lookup silently
    // asserts against the wrong actions.
    [$edit, $delete] = purchaseOrderEditAndDeleteRowActions();

    $edit->record($po);
    $delete->record($po);

    expect($edit->isHidden())->toBeTrue();
    expect($delete->isHidden())->toBeTrue();
});

it('shows Edit and Delete row actions when the PO is not linked to Xero', function () {
    $po = PurchaseOrder::create([
        'external_id' => (string) Str::uuid(),
    ]);

    [$edit, $delete] = purchaseOrderEditAndDeleteRowActions();

    $edit->record($po);
    $delete->record($po);

    expect($edit->isHidden())->toBeFalse();
    expect($delete->isHidden())->toBeFalse();
});

it('translations for sales.delivery_type and fields.sent exist in en/fr/es', function () {
    foreach (['en', 'fr', 'es'] as $locale) {
        app('translator')->setLocale($locale);
        expect(trans('laravel-crm-filament::labels.sales.delivery_type'))
            ->not->toBe('laravel-crm-filament::labels.sales.delivery_type');
        expect(trans('laravel-crm-filament::labels.fields.sent'))
            ->not->toBe('laravel-crm-filament::labels.fields.sent');
    }
    app('translator')->setLocale('en');
    expect(trans('laravel-crm-filament::labels.sales.delivery_type'))->toBe('Delivery type');
    expect(trans('laravel-crm-filament::labels.fields.sent'))->toBe('Sent');
});
