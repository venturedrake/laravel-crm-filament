<?php

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrmFilament\Resources\Orders\OrderResource;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\ListOrders;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

beforeEach(function () {
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'Order Parity Tester',
        'email' => 'order-parity-' . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->assignRole('Admin');
    $this->actingAs($this->user);
});

function orderParityInvokeHeaderActions(string $page): array
{
    $instance = (new ReflectionClass($page))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod($page, 'getHeaderActions');
    $method->setAccessible(true);

    return $method->invoke($instance);
}

it('table registers the parity column order', function () {
    $columns = livewire(ListOrders::class)->instance()->getTable()->getColumns();
    $names = array_values(array_map(fn ($c) => $c->getName(), $columns));

    expect($names)->toBe([
        'created_at',
        'order_id',
        'reference',
        'quote.quote_id',
        'labels.name',
        'person.name',
        'organization.name',
        'subtotal',
        'tax',
        'total',
        'ownerUser.name',
    ]);
});

it('created_at uses ->since() and labels.name uses ->limitList(3) and badge', function () {
    $columns = livewire(ListOrders::class)->instance()->getTable()->getColumns();

    expect($columns['labels.name']->isBadge())->toBeTrue();

    $source = file_get_contents(__DIR__ . '/../../src/Resources/Orders/OrderResource.php');
    expect($source)->toContain("Tables\\Columns\\TextColumn::make('created_at')");
    expect($source)->toContain('->since()');
    expect($source)->toContain("Tables\\Columns\\TextColumn::make('labels.name')");
    expect($source)->toContain('->limitList(3)');
});

it('Quote column resolves to a URL containing the parent quotes external_id', function () {
    $quote = Quote::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Parent quote for parity test',
    ]);
    $quote->refresh();

    $order = Order::create([
        'external_id' => (string) Str::uuid(),
        'reference' => 'ORD-WITH-QUOTE',
        'quote_id' => $quote->getKey(),
    ]);

    $columns = livewire(ListOrders::class)->instance()->getTable()->getColumns();
    $quoteColumn = $columns['quote.quote_id'];

    $url = $quoteColumn->record($order)->getUrl();

    expect($url)->not->toBeNull();
    expect($url)->toContain($quote->external_id);
    expect($url)->not->toContain((string) $quote->getKey() . '/edit');
});

it('Quote column URL is null-safe when the order has no quote', function () {
    $order = Order::create([
        'external_id' => (string) Str::uuid(),
        'reference' => 'ORD-NO-QUOTE',
    ]);

    $columns = livewire(ListOrders::class)->instance()->getTable()->getColumns();
    $quoteColumn = $columns['quote.quote_id'];

    expect($quoteColumn->record($order)->getUrl())->toBeNull();
});

it('table registers owner and labels drawer filters with the expected config', function () {
    $filters = livewire(ListOrders::class)->instance()->getTable()->getFilters();

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

    expect($owner->getSearchable())->toBeTrue();
    expect($owner->isPreloaded())->toBeTrue();
    expect($labels->isPreloaded())->toBeTrue();
});

it('row actions are buttons in View, Edit, Delete order with Delete confirmation', function () {
    $actions = array_values(livewire(ListOrders::class)->instance()->getTable()->getRecordActions());

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

it('ListOrders header exposes only a Create action (no kanban)', function () {
    $actions = orderParityInvokeHeaderActions(ListOrders::class);

    expect($actions)->toHaveCount(1);
    expect($actions[0])->toBeInstanceOf(CreateAction::class);

    $names = array_map(fn ($a) => $a->getName(), $actions);
    expect($names)->not->toContain('viewToggle');
});

it('OrderResource no longer registers a kanban page', function () {
    expect(array_keys(OrderResource::getPages()))->not->toContain('kanban');
});

it('translation keys for money.subtotal, money.tax, and money.quote exist in en/fr/es', function () {
    foreach (['en', 'fr', 'es'] as $locale) {
        app('translator')->setLocale($locale);
        expect(trans('laravel-crm-filament::labels.money.subtotal'))->not->toBe('laravel-crm-filament::labels.money.subtotal');
        expect(trans('laravel-crm-filament::labels.money.tax'))->not->toBe('laravel-crm-filament::labels.money.tax');
        expect(trans('laravel-crm-filament::labels.money.quote'))->not->toBe('laravel-crm-filament::labels.money.quote');
    }
    app('translator')->setLocale('en');
    expect(trans('laravel-crm-filament::labels.money.subtotal'))->toBe('Subtotal');
    expect(trans('laravel-crm-filament::labels.money.tax'))->toBe('Tax');
    expect(trans('laravel-crm-filament::labels.money.quote'))->toBe('Quote');
});
