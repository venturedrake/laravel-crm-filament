<?php

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrmFilament\Models\InvoicePayment;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\InvoiceResource;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\Concerns\HasInvoiceMarkPaidAction;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\EditInvoice;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\ListInvoices;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\ViewInvoice;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

beforeEach(function () {
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'Invoice Parity Tester',
        'email' => 'invoice-parity-' . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->assignRole('Admin');
    $this->actingAs($this->user);
});

it('table registers the parity column order', function () {
    $columns = livewire(ListInvoices::class)->instance()->getTable()->getColumns();
    $names = array_values(array_map(fn ($c) => $c->getName(), $columns));

    expect($names)->toBe([
        'created_at',
        'invoice_id',
        'reference',
        'order.order_id',
        'person.name',
        'organization.name',
        'issue_date',
        'due_date',
        'total',
        'fully_paid_at',
        'amount_paid',
        'amount_due',
        'overdue_by',
        'sent',
    ]);
});

it('created_at uses ->since() and Sent column is a boolean icon column', function () {
    $source = file_get_contents(__DIR__ . '/../../src/Resources/Invoices/InvoiceResource.php');
    expect($source)->toContain("Tables\\Columns\\TextColumn::make('created_at')");
    expect($source)->toContain('->since()');
    expect($source)->toContain("Tables\\Columns\\IconColumn::make('sent')");
    expect($source)->toContain('->boolean()');
});

it('Order column resolves to a URL containing the parent orders external_id', function () {
    $order = Order::create([
        'external_id' => (string) Str::uuid(),
        'reference' => 'Parent order for parity test',
    ]);
    $order->refresh();

    $invoice = Invoice::create([
        'external_id' => (string) Str::uuid(),
        'order_id' => $order->getKey(),
        'reference' => 'INV-WITH-ORDER',
    ]);

    $columns = livewire(ListInvoices::class)->instance()->getTable()->getColumns();
    $orderColumn = $columns['order.order_id'];

    $url = $orderColumn->record($invoice)->getUrl();

    expect($url)->not->toBeNull();
    expect($url)->toContain($order->external_id);
});

it('Order column URL is null-safe when the invoice has no order', function () {
    $invoice = Invoice::create([
        'external_id' => (string) Str::uuid(),
        'reference' => 'INV-NO-ORDER',
    ]);

    $columns = livewire(ListInvoices::class)->instance()->getTable()->getColumns();
    $orderColumn = $columns['order.order_id'];

    expect($orderColumn->record($invoice)->getUrl())->toBeNull();
});

it('overdue_by column computes days when due_date is past and fully_paid_at is null', function () {
    $invoice = Invoice::create([
        'external_id' => (string) Str::uuid(),
        'reference' => 'OVERDUE',
        'due_date' => now()->subDays(7)->toDateString(),
    ]);

    $columns = livewire(ListInvoices::class)->instance()->getTable()->getColumns();
    $column = $columns['overdue_by'];
    $state = $column->record($invoice->fresh())->getState();

    expect($state)->not->toBeNull();
    // diffForHumans() picks its own unit — 7 days reads "1 week" on current
    // Carbon — so assert the shape, not the unit.
    expect($state)->toEndWith(' ago');
});

it('overdue_by column is blank when fully_paid_at is set', function () {
    $invoice = Invoice::create([
        'external_id' => (string) Str::uuid(),
        'reference' => 'PAID',
        'due_date' => now()->subDays(7)->toDateString(),
        'fully_paid_at' => now()->toDateTimeString(),
    ]);

    $columns = livewire(ListInvoices::class)->instance()->getTable()->getColumns();
    $column = $columns['overdue_by'];

    expect($column->record($invoice->fresh())->getState())->toBeNull();
});

it('overdue_by column is blank when due_date is in the future', function () {
    $invoice = Invoice::create([
        'external_id' => (string) Str::uuid(),
        'reference' => 'FUTURE',
        'due_date' => now()->addDays(7)->toDateString(),
    ]);

    $columns = livewire(ListInvoices::class)->instance()->getTable()->getColumns();
    $column = $columns['overdue_by'];

    expect($column->record($invoice->fresh())->getState())->toBeNull();
});

it('table registers owner, labels, status drawer filters', function () {
    $filters = livewire(ListInvoices::class)->instance()->getTable()->getFilters();

    $byName = [];
    foreach ($filters as $filter) {
        $byName[$filter->getName()] = $filter;
    }

    expect(array_keys($byName))->toContain('user_owner_id', 'labels', 'status');

    /** @var SelectFilter $owner */
    $owner = $byName['user_owner_id'];
    /** @var SelectFilter $labels */
    $labels = $byName['labels'];

    expect($owner->isMultiple())->toBeTrue();
    expect($labels->isMultiple())->toBeTrue();
    expect($owner->getRelationshipName())->toBe('ownerUser');
    expect($labels->getRelationshipName())->toBe('labels');
});

it('status filter narrows to paid / unpaid / overdue', function () {
    Invoice::create([
        'external_id' => (string) Str::uuid(),
        'reference' => 'PAID-FILTER',
        'fully_paid_at' => now()->toDateTimeString(),
    ]);
    Invoice::create([
        'external_id' => (string) Str::uuid(),
        'reference' => 'UNPAID-FILTER',
        'due_date' => now()->addDays(5)->toDateString(),
    ]);
    Invoice::create([
        'external_id' => (string) Str::uuid(),
        'reference' => 'OVERDUE-FILTER',
        'due_date' => now()->subDays(5)->toDateString(),
    ]);

    livewire(ListInvoices::class)
        ->filterTable('status', 'paid')
        ->assertCanSeeTableRecords(Invoice::query()->whereNotNull('fully_paid_at')->get())
        ->assertCanNotSeeTableRecords(Invoice::query()->whereNull('fully_paid_at')->get());

    livewire(ListInvoices::class)
        ->filterTable('status', 'overdue')
        ->assertCanSeeTableRecords(Invoice::query()
            ->whereNull('fully_paid_at')
            ->whereNotNull('due_date')
            ->where('due_date', '<', now()->toDateString())
            ->get());
});

it('row actions are buttons in View, Edit, Delete order; Edit and Delete are hidden when amount_paid > 0', function () {
    $actions = array_values(livewire(ListInvoices::class)->instance()->getTable()->getRecordActions());

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

    // Open invoice (amount_paid == 0): both visible
    $openInvoice = Invoice::create([
        'external_id' => (string) Str::uuid(),
        'reference' => 'OPEN',
    ]);

    $editVisible = $actions[1]->record($openInvoice->fresh())->isHidden() === false;
    $deleteVisible = $actions[2]->record($openInvoice->fresh())->isHidden() === false;
    expect($editVisible)->toBeTrue();
    expect($deleteVisible)->toBeTrue();

    // Paid invoice (amount_paid > 0): both hidden
    $paidInvoice = Invoice::create([
        'external_id' => (string) Str::uuid(),
        'reference' => 'PARTIAL',
    ]);
    $paidInvoice->amount_paid = 50; // mutator stores 5000 cents
    $paidInvoice->save();

    $editHidden = $actions[1]->record($paidInvoice->fresh())->isHidden();
    $deleteHidden = $actions[2]->record($paidInvoice->fresh())->isHidden();
    expect($editHidden)->toBeTrue();
    expect($deleteHidden)->toBeTrue();
});

it('markPaidAction factory returns an Action with the expected modal form fields', function () {
    $action = InvoiceResource::markPaidAction();

    expect($action)->toBeInstanceOf(Action::class);
    expect($action->getName())->toBe('markPaid');

    $source = file_get_contents(__DIR__ . '/../../src/Resources/Invoices/InvoiceResource.php');
    // The file imports Filament\Forms\Components\TextInput, and Pint's
    // fully_qualified_strict_types fixer rewrites any `Forms\Components\`
    // prefix back to the imported short name, so assert on that form.
    expect($source)->toContain("TextInput::make('amount')");
    expect($source)->toContain("Forms\\Components\\DatePicker::make('paid_at')");
});

it('markPaidAction persists an InvoicePayment and updates amount_paid + fully_paid_at on full payment', function () {
    $invoice = Invoice::create([
        'external_id' => (string) Str::uuid(),
        'reference' => 'MARK-FULL',
    ]);
    $invoice->total = 100; // mutator stores 10000 cents
    $invoice->save();

    $action = InvoiceResource::markPaidAction();
    $action->record($invoice->fresh())->call(['data' => ['amount' => 100.0, 'paid_at' => '2026-05-27']]);

    $invoice->refresh();
    $payments = InvoicePayment::where('invoice_id', $invoice->getKey())->get();

    expect($payments)->toHaveCount(1);
    expect($payments->first()->amount)->toBe(10000);
    expect((int) $invoice->getAttributes()['amount_paid'])->toBe(10000);
    expect($invoice->fully_paid_at)->not->toBeNull();
});

it('markPaidAction persists a partial payment without setting fully_paid_at', function () {
    $invoice = Invoice::create([
        'external_id' => (string) Str::uuid(),
        'reference' => 'MARK-PARTIAL',
    ]);
    $invoice->total = 100;
    $invoice->save();

    $action = InvoiceResource::markPaidAction();
    $action->record($invoice->fresh())->call(['data' => ['amount' => 40.0, 'paid_at' => '2026-05-27']]);

    $invoice->refresh();

    expect((int) $invoice->getAttributes()['amount_paid'])->toBe(4000);
    expect($invoice->fully_paid_at)->toBeNull();
    expect(InvoicePayment::where('invoice_id', $invoice->getKey())->count())->toBe(1);
});

it('markPaidAction is wired into ViewInvoice and EditInvoice header actions via the concern', function () {
    expect(class_uses_recursive(ViewInvoice::class))->toContain(HasInvoiceMarkPaidAction::class);
    expect(class_uses_recursive(EditInvoice::class))->toContain(HasInvoiceMarkPaidAction::class);

    foreach ([ViewInvoice::class, EditInvoice::class] as $page) {
        $instance = (new ReflectionClass($page))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($page, 'getHeaderActions');
        $method->setAccessible(true);
        $actions = $method->invoke($instance);
        $names = array_map(fn ($a) => $a->getName(), $actions);
        expect($names)->toContain('markPaid');
    }
});

it('translation keys for mark_paid, record_payment, money.amount_paid/due/overdue_by exist in en/fr/es', function () {
    foreach (['en', 'fr', 'es'] as $locale) {
        app('translator')->setLocale($locale);
        foreach ([
            'laravel-crm-filament::labels.actions.mark_paid',
            'laravel-crm-filament::labels.actions.record_payment',
            'laravel-crm-filament::labels.money.amount_paid',
            'laravel-crm-filament::labels.money.amount_due',
            'laravel-crm-filament::labels.money.overdue_by',
            'laravel-crm-filament::labels.fields.sent',
        ] as $key) {
            expect(trans($key))->not->toBe($key);
        }
    }
    app('translator')->setLocale('en');
    expect(trans('laravel-crm-filament::labels.actions.mark_paid'))->toBe('Mark paid');
    expect(trans('laravel-crm-filament::labels.money.overdue_by'))->toBe('Overdue by');
});
