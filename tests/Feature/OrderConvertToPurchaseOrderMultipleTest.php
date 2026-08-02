<?php

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\OrderProduct;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\PurchaseOrder;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\Concerns\HasOrderConvertToPurchaseOrderAction;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\ViewOrder;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

/**
 * US-010 — parity with base's purchase-orders.store-multiple: the
 * convert-to-purchase-order action is a modal with a line-item CheckboxList and
 * a split_by_supplier toggle, and the payload builder returns one payload per
 * supplier group.
 */
beforeEach(function () {
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'PO Multiple Tester',
        'email' => 'po-multiple-' . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->assignRole('Admin');
    $this->actingAs($this->user);

    // Product::getDefaultPrice() reads Setting::currency()->value; without the
    // row the scope returns the Builder and the read explodes.
    Setting::create(['name' => 'currency', 'value' => 'USD']);

    $this->supplierA = Organization::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Supplier A',
    ]);

    $this->supplierB = Organization::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Supplier B',
    ]);

    $this->order = Order::create([
        'external_id' => (string) Str::uuid(),
        'reference' => 'ORD-REF-1',
        'currency' => 'USD',
        'user_owner_id' => $this->user->id,
    ]);

    $this->lines = collect(['Widget', 'Gadget', 'Doohickey'])->map(function (string $name, int $index) {
        $product = Product::create([
            'external_id' => (string) Str::uuid(),
            'name' => $name,
            'user_owner_id' => $this->user->id,
        ]);

        $product->productPrices()->create([
            'external_id' => (string) Str::uuid(),
            'unit_price' => 20 + $index,
            'cost_per_unit' => 10 + $index,
            'currency' => 'USD',
        ]);

        return OrderProduct::create([
            'external_id' => (string) Str::uuid(),
            'order_id' => $this->order->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => 20 + $index,
            'amount' => (20 + $index) * 2,
        ]);
    });
});

function orderConvertPage(Order $order): ViewOrder
{
    $page = (new ReflectionClass(ViewOrder::class))->newInstanceWithoutConstructor();
    $page->record = $order;

    return $page;
}

function invokeOrderConvert(ViewOrder $page, string $method, mixed ...$args): mixed
{
    $reflection = new ReflectionMethod(ViewOrder::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($page, ...$args);
}

/**
 * Run the action's own closure with the modal payload. The ViewOrder page
 * cannot be rendered under Pest (cknow/laravel-money cannot load its ISO code
 * table in the testbench), so the action is called directly instead of via
 * livewire()->callAction().
 */
function callConvertToPurchaseOrder(Order $order, array $data): void
{
    $page = orderConvertPage($order);
    $action = invokeOrderConvert($page, 'orderConvertToPurchaseOrderAction')->record($order);

    $action->call(['data' => $data]);
}

it('opens a modal with a line-item CheckboxList and a split_by_supplier toggle', function () {
    $page = orderConvertPage($this->order);
    $action = invokeOrderConvert($page, 'orderConvertToPurchaseOrderAction')->record($this->order);

    $components = $action->getSchema(Schema::make($page))->getComponents(withHidden: true);

    $lineItems = collect($components)->first(fn ($c) => $c instanceof CheckboxList && $c->getName() === 'line_items');
    $toggle = collect($components)->first(fn ($c) => $c instanceof Toggle && $c->getName() === 'split_by_supplier');

    expect($lineItems)->not->toBeNull();
    expect($toggle)->not->toBeNull();
    expect(array_keys($lineItems->getOptions()))
        ->toEqualCanonicalizing($this->lines->pluck('id')->all());
});

it('selects every line item by default', function () {
    $page = orderConvertPage($this->order);
    $action = invokeOrderConvert($page, 'orderConvertToPurchaseOrderAction')->record($this->order);

    $components = $action->getSchema(Schema::make($page))->getComponents(withHidden: true);

    $lineItems = collect($components)->first(fn ($c) => $c instanceof CheckboxList && $c->getName() === 'line_items');

    expect($lineItems->getDefaultState())->toEqualCanonicalizing($this->lines->pluck('id')->all());
});

it('hides the per-line supplier selects until split_by_supplier is on', function () {
    $page = orderConvertPage($this->order);
    $schema = invokeOrderConvert($page, 'purchaseOrderConversionSchema', $this->order);

    $section = collect($schema)->first(fn ($c) => $c instanceof Section);

    expect($section)->not->toBeNull();

    $selects = $section->getDefaultChildComponents();

    expect($selects)->toHaveCount(3);
    expect(collect($selects)->map(fn ($s) => $s->getName())->all())
        ->toEqualCanonicalizing($this->lines->map(fn ($l) => 'suppliers.' . $l->id)->all());
});

it('builds one payload holding every line when no data is supplied', function () {
    $page = orderConvertPage($this->order);
    $payloads = invokeOrderConvert($page, 'buildPurchaseOrderPayloadsFromOrder', $this->order, []);

    expect($payloads)->toHaveCount(1);
    expect($payloads[0]['products'])->toHaveCount(3);
    expect($payloads[0]['organization_id'])->toBeNull();
    expect($payloads[0]['order_id'])->toBe($this->order->id);
    expect($payloads[0]['reference'])->toBe('ORD-REF-1');
});

it('builds one payload holding only the selected lines', function () {
    $page = orderConvertPage($this->order);
    $payloads = invokeOrderConvert($page, 'buildPurchaseOrderPayloadsFromOrder', $this->order, [
        'line_items' => [$this->lines[0]->id],
        'split_by_supplier' => false,
    ]);

    expect($payloads)->toHaveCount(1);
    expect($payloads[0]['products'])->toHaveCount(1);
    expect($payloads[0]['products'][0]['order_product_id'])->toBe($this->lines[0]->id);
});

it('builds one payload per supplier group when split_by_supplier is on', function () {
    $page = orderConvertPage($this->order);
    $payloads = invokeOrderConvert($page, 'buildPurchaseOrderPayloadsFromOrder', $this->order, [
        'line_items' => $this->lines->pluck('id')->all(),
        'split_by_supplier' => true,
        'suppliers' => [
            $this->lines[0]->id => $this->supplierA->id,
            $this->lines[1]->id => $this->supplierB->id,
            $this->lines[2]->id => $this->supplierA->id,
        ],
    ]);

    expect($payloads)->toHaveCount(2);

    $bySupplier = collect($payloads)->keyBy('organization_id');

    expect($bySupplier[$this->supplierA->id]['products'])->toHaveCount(2);
    expect($bySupplier[$this->supplierB->id]['products'])->toHaveCount(1);
});

it('groups lines with no supplier into a single unassigned payload', function () {
    $page = orderConvertPage($this->order);
    $payloads = invokeOrderConvert($page, 'buildPurchaseOrderPayloadsFromOrder', $this->order, [
        'line_items' => $this->lines->pluck('id')->all(),
        'split_by_supplier' => true,
        'suppliers' => [
            $this->lines[0]->id => $this->supplierA->id,
            $this->lines[1]->id => null,
            $this->lines[2]->id => '',
        ],
    ]);

    expect($payloads)->toHaveCount(2);

    $unassigned = collect($payloads)->firstWhere('organization_id', null);

    expect($unassigned['products'])->toHaveCount(2);
});

it('ignores the supplier map entirely when split_by_supplier is off', function () {
    $page = orderConvertPage($this->order);
    $payloads = invokeOrderConvert($page, 'buildPurchaseOrderPayloadsFromOrder', $this->order, [
        'line_items' => $this->lines->pluck('id')->all(),
        'split_by_supplier' => false,
        'suppliers' => [
            $this->lines[0]->id => $this->supplierA->id,
            $this->lines[1]->id => $this->supplierB->id,
        ],
    ]);

    expect($payloads)->toHaveCount(1);
    expect($payloads[0]['organization_id'])->toBeNull();
});

it('prices each line from the product supplier cost, not the order line price', function () {
    $page = orderConvertPage($this->order);
    $payloads = invokeOrderConvert($page, 'buildPurchaseOrderPayloadsFromOrder', $this->order, []);

    $line = collect($payloads[0]['products'])->firstWhere('order_product_id', $this->lines[0]->id);

    // cost_per_unit was stored as 10 (mutator × 100 → 1000 cents).
    expect($line['unit_price'])->toBe(10.0);
    expect($line['amount'])->toBe(20.0);
});

it('creates a single purchase order from the selected lines with split off', function () {
    callConvertToPurchaseOrder($this->order, [
        'line_items' => [$this->lines[0]->id, $this->lines[1]->id],
        'split_by_supplier' => false,
    ]);

    $purchaseOrders = PurchaseOrder::all();

    expect($purchaseOrders)->toHaveCount(1);
    expect($purchaseOrders->first()->order_id)->toBe($this->order->id);
    expect($purchaseOrders->first()->purchaseOrderLines)->toHaveCount(2);
});

it('creates one purchase order per supplier group with split on', function () {
    callConvertToPurchaseOrder($this->order, [
        'line_items' => $this->lines->pluck('id')->all(),
        'split_by_supplier' => true,
        'suppliers' => [
            $this->lines[0]->id => $this->supplierA->id,
            $this->lines[1]->id => $this->supplierB->id,
            $this->lines[2]->id => $this->supplierA->id,
        ],
    ]);

    $purchaseOrders = PurchaseOrder::all();

    expect($purchaseOrders)->toHaveCount(2);
    expect($purchaseOrders->pluck('organization_id')->all())
        ->toEqualCanonicalizing([$this->supplierA->id, $this->supplierB->id]);

    $forA = $purchaseOrders->firstWhere('organization_id', $this->supplierA->id);
    $forB = $purchaseOrders->firstWhere('organization_id', $this->supplierB->id);

    expect($forA->purchaseOrderLines)->toHaveCount(2);
    expect($forB->purchaseOrderLines)->toHaveCount(1);
});

it('requires at least one selected line item', function () {
    $page = orderConvertPage($this->order);
    $action = invokeOrderConvert($page, 'orderConvertToPurchaseOrderAction')->record($this->order);

    $components = $action->getSchema(Schema::make($page))->getComponents(withHidden: true);
    $lineItems = collect($components)->first(fn ($c) => $c instanceof CheckboxList && $c->getName() === 'line_items');

    expect($lineItems->isRequired())->toBeTrue();

    // Belt and braces: an empty selection yields no payloads, so nothing is
    // created even if the required rule is ever bypassed.
    callConvertToPurchaseOrder($this->order, [
        'line_items' => [],
        'split_by_supplier' => false,
    ]);

    expect(PurchaseOrder::count())->toBe(0);
});

it('routes every group through PurchaseOrderService::create with a wrapped FormPayload', function () {
    $source = file_get_contents(
        (new ReflectionClass(
            HasOrderConvertToPurchaseOrderAction::class
        ))->getFileName()
    );

    expect($source)->toContain('foreach ($payloads as $payload)');
    expect($source)->toContain('$purchaseOrderService->create(');
    expect($source)->toContain('FormPayload::wrap($payload)');
});
