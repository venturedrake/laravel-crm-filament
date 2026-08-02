<?php

use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use VentureDrake\LaravelCrmFilament\Resources\Deals\DealResource;
use VentureDrake\LaravelCrmFilament\Resources\Deliveries\DeliveryResource;
use VentureDrake\LaravelCrmFilament\Resources\Deliveries\Pages\ListDeliveries;
use VentureDrake\LaravelCrmFilament\Resources\Features\FeatureResource;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\InvoiceResource;
use VentureDrake\LaravelCrmFilament\Resources\Leads\LeadResource;
use VentureDrake\LaravelCrmFilament\Resources\Orders\OrderResource;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\ListOrders;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\OrganizationResource;
use VentureDrake\LaravelCrmFilament\Resources\People\PersonResource;
use VentureDrake\LaravelCrmFilament\Resources\Products\Pages\ListProducts;
use VentureDrake\LaravelCrmFilament\Resources\Products\ProductResource;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\PurchaseOrderResource;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\ListQuotes;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\QuoteResource;
use VentureDrake\LaravelCrmFilament\Resources\Tasks\Pages\ListTasks;
use VentureDrake\LaravelCrmFilament\Resources\Tasks\TaskResource;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * US-010 — the ExportsCsv bulk action must be present on every list resource
 * that carries one, not just the six it originally shipped on.
 */
beforeEach(function () {
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'CSV Coverage Tester',
        'email' => 'csv-coverage-' . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->assignRole('Admin');
    $this->actingAs($this->user);
});

/**
 * Flatten a table's toolbar actions (groups included) down to the exportCsv
 * BulkAction, or null when the resource has none.
 */
function exportCsvBulkAction(string $resource, string $listPage): ?BulkAction
{
    $table = $resource::table(Table::make(livewire($listPage)->instance()));

    $flatten = function (array $actions) use (&$flatten): array {
        $flat = [];

        foreach ($actions as $action) {
            if ($action instanceof BulkActionGroup) {
                $flat = array_merge($flat, $flatten($action->getActions()));

                continue;
            }

            $flat[] = $action;
        }

        return $flat;
    };

    return collect($flatten($table->getToolbarActions()))
        ->first(fn ($action) => $action instanceof BulkAction && $action->getName() === 'exportCsv');
}

dataset('csv export resources', [
    'quotes' => [QuoteResource::class, ListQuotes::class],
    'orders' => [OrderResource::class, ListOrders::class],
    'purchase orders' => [PurchaseOrderResource::class, ListPurchaseOrders::class],
    'deliveries' => [DeliveryResource::class, ListDeliveries::class],
    'products' => [ProductResource::class, ListProducts::class],
    'tasks' => [TaskResource::class, ListTasks::class],
]);

it('exposes the exportCsv bulk action on the newly covered resources', function (string $resource, string $listPage) {
    expect(exportCsvBulkAction($resource, $listPage))->toBeInstanceOf(BulkAction::class);
})->with('csv export resources');

it('keeps the exportCsv bulk action on the resources that already had it', function () {
    $alreadyCovered = [
        LeadResource::class,
        DealResource::class,
        InvoiceResource::class,
        FeatureResource::class,
        PersonResource::class,
        OrganizationResource::class,
    ];

    foreach ($alreadyCovered as $resource) {
        $source = file_get_contents((new ReflectionClass($resource))->getFileName());

        expect($source)->toContain('ExportsCsv::action(');
    }
});

dataset('money csv resources', [
    'quotes' => [QuoteResource::class],
    'orders' => [OrderResource::class],
    'purchase orders' => [PurchaseOrderResource::class],
    'deliveries' => [DeliveryResource::class],
    'products' => [ProductResource::class],
]);

it('divides money columns by 100 in the column map', function (string $resource) {
    $source = file_get_contents((new ReflectionClass($resource))->getFileName());

    $export = substr($source, strpos($source, 'ExportsCsv::action('));

    expect($export)->toMatch('/\)\s*\/ 100,/');
})->with('money csv resources');

it('resolves the owner via optional($r->ownerUser)->name', function (string $resource, string $listPage) {
    $source = file_get_contents((new ReflectionClass($resource))->getFileName());

    $export = substr($source, strpos($source, 'ExportsCsv::action('));

    expect($export)->toContain('optional($r->ownerUser)->name');
})->with('csv export resources');

it('streams a CSV body for each newly covered resource', function (string $resource, string $listPage) {
    $action = exportCsvBulkAction($resource, $listPage);

    $response = ($action->getActionFunction())(new Collection);

    ob_start();
    $response->sendContent();
    $body = ob_get_clean();

    // BOM + a header row is enough: the row-level behaviour is covered by
    // ExportsCsvActionTest.
    expect(substr($body, 0, 3))->toBe(chr(0xEF) . chr(0xBB) . chr(0xBF));
    expect(trim(substr($body, 3)))->not->toBe('');
})->with('csv export resources');

it('names the download file after the resource', function () {
    $expectations = [
        QuoteResource::class => 'quotes',
        OrderResource::class => 'orders',
        PurchaseOrderResource::class => 'purchase-orders',
        DeliveryResource::class => 'deliveries',
        ProductResource::class => 'products',
        TaskResource::class => 'tasks',
    ];

    foreach ($expectations as $resource => $filename) {
        $source = file_get_contents((new ReflectionClass($resource))->getFileName());

        expect($source)->toContain("filename: '{$filename}',");
    }
});
