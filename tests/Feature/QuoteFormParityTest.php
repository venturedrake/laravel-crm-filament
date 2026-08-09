<?php

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\LineItemsRepeater;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\MoneyTotalsRow;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\SalesDetailsSection;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\CreateQuote;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

beforeEach(function () {
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'Quote Form Parity Tester',
        'email' => 'quote-form-parity-' . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->assignRole('Admin');
    $this->actingAs($this->user);

    Setting::create([
        'external_id' => '11111111-1111-1111-1111-111111111111',
        'name' => 'currency',
        'value' => 'USD',
    ]);
});

function quoteFormChildrenOf(Section $section): array
{
    $ref = new ReflectionProperty($section, 'childComponents');
    $ref->setAccessible(true);
    $children = $ref->getValue($section);

    return $children['default'] ?? $children;
}

it('QuoteResource source uses the shared form concerns and the 2-col Grid', function () {
    $source = file_get_contents(__DIR__ . '/../../src/Resources/Quotes/QuoteResource.php');
    expect($source)->toContain("Grid::make(['default' => 1, 'lg' => 2])");
    expect($source)->toContain('SalesDetailsSection::make([');
    expect($source)->toContain("LineItemsRepeater::products(\n                            fkColumn: 'quote_product_id',");
    expect($source)->toContain('MoneyTotalsRow::make()');
    expect($source)->toContain("'issueDateKey' => 'issue_at'");
    expect($source)->toContain("'expiryDateKey' => 'expire_at'");
});

it('SalesDetailsSection includes person + organization Selects and the requested scalars for Quote', function () {
    $section = SalesDetailsSection::make([
        'title' => true,
        'description' => true,
        'reference' => true,
        'currency' => true,
        'issueDateKey' => 'issue_at',
        'expiryDateKey' => 'expire_at',
        'terms' => true,
        'stage' => true,
        'owner' => true,
        'labels' => false,
    ]);

    expect($section)->toBeInstanceOf(Section::class);

    $children = quoteFormChildrenOf($section);

    // Pluck "leaf" component names by walking into Grids one level.
    $names = [];
    foreach ($children as $child) {
        if ($child instanceof Grid) {
            $gridRef = new ReflectionProperty($child, 'childComponents');
            $gridRef->setAccessible(true);
            foreach ($gridRef->getValue($child)['default'] ?? [] as $inner) {
                $names[] = $inner->getName();
            }
        } else {
            $names[] = $child->getName();
        }
    }

    expect($names)->toContain('person_id', 'organization_id', 'title', 'description', 'reference', 'currency', 'issue_at', 'expire_at', 'terms', 'pipeline_stage_id', 'user_owner_id');
});

it('MoneyTotalsRow renders the 5 monetary fields with total read-only', function () {
    $grid = MoneyTotalsRow::make();
    expect($grid)->toBeInstanceOf(Grid::class);

    // Outer Grid is 2-col on md+; the right column holds a 1-col stack of 5
    // inlineLabel TextInputs. Walk into the right-side inner Grid.
    $outerRef = new ReflectionProperty($grid, 'childComponents');
    $outerRef->setAccessible(true);
    $outer = $outerRef->getValue($grid)['default'] ?? [];
    expect($outer)->toHaveCount(2);

    $rightGrid = $outer[1];
    expect($rightGrid)->toBeInstanceOf(Grid::class);

    $innerRef = new ReflectionProperty($rightGrid, 'childComponents');
    $innerRef->setAccessible(true);
    $components = $innerRef->getValue($rightGrid)['default'] ?? [];

    $names = array_map(fn ($c) => $c->getName(), $components);
    expect($names)->toBe([
        'sub_total',
        'discount',
        'tax',
        'adjustment',
        'total',
    ]);

    // sub_total (index 0), tax (index 2), and total (index 4) are read-only;
    // discount (index 1) and adjustment (index 3) remain editable.
    $readOnlyRef = new ReflectionProperty(TextInput::class, 'isReadOnly');
    $readOnlyRef->setAccessible(true);

    foreach ([0, 2, 4] as $i) {
        expect($readOnlyRef->getValue($components[$i]))->toBeTrue();
    }
    foreach ([1, 3] as $i) {
        expect($readOnlyRef->getValue($components[$i]))->toBeFalse();
    }
});

it('LineItemsRepeater for Quote uses unit_price and quote_product_id', function () {
    /** @var Repeater $repeater */
    $repeater = LineItemsRepeater::products('quote_product_id', 'unit_price');

    // Walk one level into the nested row-2 Grid; for $priceField === 'unit_price'
    // the inner Grid is 4-col and includes a read-only tax_amount field.
    $names = quoteFormCollectLeafNames($repeater);

    expect($names)->toBe([
        'quote_product_id',
        'id',
        'unit_price',
        'quantity',
        'tax_amount',
        'amount',
        'comments',
    ]);
});

it('CreateQuote persists a Quote with line items and the resolved person/organization', function () {
    $person = Person::create([
        'external_id' => (string) Str::uuid(),
        'first_name' => 'Existing',
        'last_name' => 'Contact',
    ]);
    $organization = Organization::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Existing Org',
    ]);
    $product = Product::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Widget',
        'active' => true,
    ]);

    livewire(CreateQuote::class)
        ->fillForm([
            'title' => 'My new quote',
            'currency' => 'USD',
            'person_id' => $person->getKey(),
            'organization_id' => $organization->getKey(),
            'sub_total' => 300,
            'tax' => 30,
            'total' => 330,
        ])
        ->set('data.products', [
            'row1' => [
                'id' => $product->getKey(),
                'unit_price' => 100,
                'quantity' => 3,
                'amount' => 300,
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $quote = Quote::query()->where('title', 'My new quote')->first();
    expect($quote)->not->toBeNull();
    expect($quote->person_id)->toBe($person->getKey());
    expect($quote->organization_id)->toBe($organization->getKey());
    expect($quote->quoteProducts()->count())->toBe(1);
});

it('discount and adjustment translation keys exist in en/fr/es', function () {
    foreach (['en', 'fr', 'es'] as $locale) {
        app('translator')->setLocale($locale);
        expect(trans('laravel-crm-filament::labels.money.discount'))->not->toBe('laravel-crm-filament::labels.money.discount');
        expect(trans('laravel-crm-filament::labels.money.adjustment'))->not->toBe('laravel-crm-filament::labels.money.adjustment');
    }
    app('translator')->setLocale('en');
    expect(trans('laravel-crm-filament::labels.money.discount'))->toBe('Discount');
    expect(trans('laravel-crm-filament::labels.money.adjustment'))->toBe('Adjustment');
});

function quoteFormCollectLeafNames(Repeater | Section | Grid $component): array
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
