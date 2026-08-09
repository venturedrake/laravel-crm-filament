<?php

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\LineItemsRepeater;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\MoneyTotalsRow;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\SalesDetailsSection;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\CreateInvoice;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

beforeEach(function () {
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'Invoice Form Parity Tester',
        'email' => 'invoice-form-parity-' . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->assignRole('Admin');
    $this->actingAs($this->user);

    Setting::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'currency',
        'value' => 'USD',
    ]);
});

it('InvoiceResource source uses the shared form concerns + order_id orderLink', function () {
    $source = file_get_contents(__DIR__ . '/../../src/Resources/Invoices/InvoiceResource.php');
    expect($source)->toContain("Grid::make(['default' => 1, 'lg' => 2])");
    expect($source)->toContain('SalesDetailsSection::make([');
    expect($source)->toContain("'orderLink' => true");
    expect($source)->toContain("LineItemsRepeater::products(\n                            fkColumn: 'invoice_line_id',");
    expect($source)->toContain('MoneyTotalsRow::make()');
});

it('SalesDetailsSection with orderLink puts order_id first', function () {
    $section = SalesDetailsSection::make([
        'orderLink' => true,
        'reference' => true,
        'currency' => true,
        'issueDateKey' => 'issue_date',
        'expiryDateKey' => 'due_date',
        'terms' => true,
        'owner' => true,
    ]);

    $ref = new ReflectionProperty($section, 'childComponents');
    $ref->setAccessible(true);
    $children = $ref->getValue($section)['default'] ?? [];

    expect($children[0])->toBeInstanceOf(Select::class);
    expect($children[0]->getName())->toBe('order_id');
});

it('CreateInvoice persists Invoice + discount + adjustment columns past the service', function () {
    $person = Person::create([
        'external_id' => (string) Str::uuid(),
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
    ]);
    $organization = Organization::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Org Co',
    ]);
    $order = Order::create([
        'external_id' => (string) Str::uuid(),
        'reference' => 'ORD-1',
    ]);

    livewire(CreateInvoice::class)
        ->fillForm([
            'reference' => 'INV-PARITY',
            'currency' => 'USD',
            'order_id' => $order->getKey(),
            'person_id' => $person->getKey(),
            'organization_id' => $organization->getKey(),
            'sub_total' => 100,
            'discount' => 5,
            'tax' => 10,
            'adjustment' => 2,
            'total' => 107,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $invoice = Invoice::query()->where('reference', 'INV-PARITY')->first();
    expect($invoice)->not->toBeNull();
    expect($invoice->getAttributes()['subtotal'])->toBe(100 * 100);
    expect($invoice->getAttributes()['discount'])->toBe(5 * 100);
    expect($invoice->getAttributes()['tax'])->toBe(10 * 100);
    expect($invoice->getAttributes()['adjustments'])->toBe(2 * 100);
});

it('LineItemsRepeater for Invoice uses invoice_line_id and unit_price', function () {
    /** @var Repeater $repeater */
    $repeater = LineItemsRepeater::products('invoice_line_id', 'unit_price');

    // Walk one level into the nested row-2 Grid; unit_price variant has tax_amount.
    $names = invoiceFormCollectLeafNames($repeater);

    expect($names)->toBe([
        'invoice_line_id',
        'id',
        'unit_price',
        'quantity',
        'tax_amount',
        'amount',
        'comments',
    ]);
});

it('MoneyTotalsRow remains a 5-col Grid', function () {
    expect(MoneyTotalsRow::make())->toBeInstanceOf(Grid::class);
});

function invoiceFormCollectLeafNames(Repeater | Section | Grid $component): array
{
    $ref = new ReflectionProperty($component, 'childComponents');
    $ref->setAccessible(true);
    $group = $ref->getValue($component);
    $children = $group['default'] ?? $group;

    $names = [];
    foreach ($children as $child) {
        if ($child instanceof Grid) {
            $gref = new ReflectionProperty($child, 'childComponents');
            $gref->setAccessible(true);
            $ggroup = $gref->getValue($child);
            foreach (($ggroup['default'] ?? $ggroup) as $inner) {
                $names[] = $inner->getName();
            }
        } else {
            $names[] = $child->getName();
        }
    }

    return $names;
}
