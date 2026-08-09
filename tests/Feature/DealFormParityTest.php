<?php

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Deal;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\LeadDealContactSection;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\LineItemsRepeater;
use VentureDrake\LaravelCrmFilament\Resources\Deals\Pages\CreateDeal;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

beforeEach(function () {
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'Deal Form Parity Tester',
        'email' => 'deal-form-parity-' . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->assignRole('Admin');
    $this->actingAs($this->user);

    Setting::create(['external_id' => '11111111-1111-1111-1111-111111111111', 'name' => 'currency', 'value' => 'USD']);
});

function dealFormChildAt(Section $section, int $index = 0)
{
    $ref = new ReflectionProperty($section, 'childComponents');
    $ref->setAccessible(true);
    $children = $ref->getValue($section);
    $group = $children['default'] ?? $children;

    return $group[$index] ?? null;
}

it('DealResource source contains the 2-col Grid and the shared concerns', function () {
    $source = file_get_contents(__DIR__ . '/../../src/Resources/Deals/DealResource.php');
    expect($source)->toContain("Grid::make(['default' => 1, 'lg' => 2])");
    expect($source)->toContain('LeadDealContactSection::contactColumn()');
    expect($source)->toContain('LeadDealContactSection::organizationColumn()');
    expect($source)->toContain("LineItemsRepeater::products('deal_product_id')");
    expect($source)->toContain("Section::make(__('laravel-crm-filament::labels.sections.details'))");
    expect($source)->toContain("Section::make(__('laravel-crm-filament::labels.sections.products'))");
});

it('LineItemsRepeater produces a reorderable Repeater with the expected FK and row shape', function () {
    /** @var Repeater $repeater */
    $repeater = LineItemsRepeater::products('deal_product_id');

    expect($repeater)->toBeInstanceOf(Repeater::class);
    expect($repeater->getName())->toBe('products');
    $reorderProp = new ReflectionProperty($repeater, 'isReorderable');
    $reorderProp->setAccessible(true);
    expect($reorderProp->getValue($repeater))->toBeTrue();

    // Walk one level into nested Grids — row 2 is a 3-col Grid for Deal (no tax_amount).
    $names = dealFormCollectLeafNames($repeater);
    expect($names)->toBe([
        'deal_product_id',
        'id',
        'price',
        'quantity',
        'amount',
        'comments',
    ]);
});

it('LineItemsRepeater FK column changes with the constructor arg', function () {
    $repeater = LineItemsRepeater::products('quote_product_id', 'unit_price');
    $names = dealFormCollectLeafNames($repeater);

    expect($names[0])->toBe('quote_product_id');
});

it('Contact section is reused from LeadDealContactSection', function () {
    $section = LeadDealContactSection::contactColumn();
    $select = dealFormChildAt($section);

    expect($select)->toBeInstanceOf(Select::class);
    expect($select->getName())->toBe('person_id');
});

it('CreateDeal resolves person_id and organization_id and persists line items via DealService', function () {
    $person = Person::create([
        'external_id' => (string) Str::uuid(),
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
    ]);
    $organization = Organization::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Analytic Engines Ltd',
    ]);
    $product = Product::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Widget',
        'active' => true,
    ]);

    livewire(CreateDeal::class)
        ->fillForm([
            'title' => 'My new deal',
            'description' => 'desc',
            'currency' => 'USD',
            'amount' => 1500,
            'person_id' => $person->getKey(),
            'organization_id' => $organization->getKey(),
        ])
        ->set('data.products', [
            'row1' => [
                'id' => $product->getKey(),
                'price' => 100,
                'quantity' => 3,
                'amount' => 300,
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $deal = Deal::query()->where('title', 'My new deal')->first();
    expect($deal)->not->toBeNull();
    expect($deal->person_id)->toBe($person->getKey());
    expect($deal->organization_id)->toBe($organization->getKey());
    expect($deal->dealProducts()->count())->toBe(1);
    $line = $deal->dealProducts()->first();
    expect($line->product_id)->toBe($product->getKey());
    // Stored as cents (×100).
    expect($line->price)->toBe(100 * 100);
    // decimal(15,3) since core 2.4.0, so this comes back as a float.
    expect((float) $line->quantity)->toBe(3.0);
    expect($line->amount)->toBe(300 * 100);
});

function dealFormCollectLeafNames(Repeater | Section | Grid $component): array
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
