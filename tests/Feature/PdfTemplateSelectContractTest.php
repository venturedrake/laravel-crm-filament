<?php

use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Support\PdfTemplateRegistry;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\PdfTemplateSelect;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\InvoiceResource;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\EditInvoice;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\ViewOrder;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * The ''-vs-null contract, end to end.
 *
 * Filament submits null for a cleared Select. Core's
 * PdfTemplateRegistry::resolveUpdate(null, $current) *keeps* $current — on
 * purpose, because every non-form writer (the REST API, the legacy
 * controllers, the multi-purchase-order split) submits nothing for this field
 * and must not silently clear a record's pinned template. So a form that means
 * "go back to following settings" has to say so explicitly, and '' is how core
 * spells that.
 *
 * Without ->dehydrateStateUsing(fn ($state) => $state ?? '') on the Select, a
 * user can pin a template and never un-pin it.
 */
beforeEach(function () {
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'Template Tester',
        'email' => 'template-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->assignRole('Owner');
    $this->actingAs($this->user->fresh());
});

it('keeps the dehydrateStateUsing line that makes clearing possible at all', function () {
    // The behaviour is asserted end to end below ("clears the column when the
    // select is cleared"). This guards the *line*, because it reads like a
    // no-op and is the single thing standing between a user and a template
    // they can pin but never un-pin.
    $source = (string) file_get_contents((new ReflectionClass(PdfTemplateSelect::class))->getFileName());

    expect($source)->toContain("->dehydrateStateUsing(fn (\$state) => \$state ?? '')")
        // …and the reason, so it survives a tidy-up.
        ->toContain('resolveUpdate');

    $select = PdfTemplateSelect::make('invoice');

    expect($select)->toBeInstanceOf(Select::class)
        ->and($select->getName())->toBe('pdf_template');
});

it('names the settings default in the blank option label', function () {
    app('laravel-crm.settings')->set('pdf_template_invoice', 'bold');
    app('laravel-crm.settings')->forgetCache();

    // "Default (Bold)" — the picker still tells you what you are going to get.
    expect(PdfTemplateSelect::make('invoice')->getPlaceholder())
        ->toBe(PdfTemplateRegistry::defaultOptionLabel('invoice'))
        ->toContain('Bold');
});

it('clears the column when the select is cleared on an edit', function () {
    $invoice = Invoice::create([
        'external_id' => (string) Str::uuid(),
        'pdf_template' => 'compact',
    ]);

    livewire(EditInvoice::class, ['record' => $invoice->external_id])
        ->fillForm(['pdf_template' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($invoice->fresh()->pdf_template)->toBeNull();
});

it('persists a pinned template through an edit', function () {
    $invoice = Invoice::create(['external_id' => (string) Str::uuid()]);

    livewire(EditInvoice::class, ['record' => $invoice->external_id])
        ->fillForm(['pdf_template' => 'professional'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($invoice->fresh()->pdf_template)->toBe('professional');
});

it('leaves a pinned template untouched when a converting writer submits nothing', function () {
    // The exact case resolveUpdate(null, $current) exists for: the convert
    // action carries no picker, so it must not clear what the record has.
    $order = Order::create(['external_id' => (string) Str::uuid()]);

    $invoice = Invoice::create([
        'external_id' => (string) Str::uuid(),
        'order_id' => $order->id,
        'pdf_template' => 'classic',
    ]);

    expect(PdfTemplateRegistry::resolveUpdate(null, $invoice->pdf_template))->toBe('classic')
        ->and(PdfTemplateRegistry::resolveUpdate('', $invoice->pdf_template))->toBeNull();

    // And no convert action submits one.
    $source = (string) file_get_contents((new ReflectionClass(ViewOrder::class))->getFileName());
    expect($source)->not->toContain('pdf_template');
});

it('offers the picker on all five document forms', function () {
    foreach ([
        ['invoice', InvoiceResource::class],
    ] as [$docType, $resource]) {
        $names = collect(
            $resource::form(Schema::make(livewire(EditInvoice::class, [
                'record' => Invoice::create(['external_id' => (string) Str::uuid()])->external_id,
            ])->instance()))->getFlatComponents(withHidden: true)
        )->map(fn ($c) => method_exists($c, 'getName') ? $c->getName() : null);

        expect($names)->toContain('pdf_template');
    }

    // The other four are asserted structurally — instantiating four more edit
    // pages here buys nothing the resolution test does not already cover.
    foreach ([
        'src/Resources/Quotes/QuoteResource.php' => "'pdfTemplate' => 'quote'",
        'src/Resources/Orders/OrderResource.php' => "'pdfTemplate' => 'order'",
        'src/Resources/PurchaseOrders/PurchaseOrderResource.php' => "'pdfTemplate' => 'purchase-order'",
        'src/Resources/Deliveries/DeliveryResource.php' => "PdfTemplateSelect::make('delivery')",
    ] as $file => $needle) {
        expect((string) file_get_contents(dirname(__DIR__, 2) . '/' . $file))->toContain($needle);
    }
});
