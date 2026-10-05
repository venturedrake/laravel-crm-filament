<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Address;
use VentureDrake\LaravelCrm\Models\Delivery;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\ProductPrice;
use VentureDrake\LaravelCrm\Models\Task;
use VentureDrake\LaravelCrmFilament\Resources\Deliveries\Pages\ListDeliveries;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\Pages\ListOrganizations;
use VentureDrake\LaravelCrmFilament\Resources\People\Pages\ListPeople;
use VentureDrake\LaravelCrmFilament\Resources\Products\Pages\ListProducts;
use VentureDrake\LaravelCrmFilament\Support\NextActivity;

use function Pest\Livewire\livewire;

/**
 * The per-row reads core 2.4.3 cut out of its own index pages: every cell a
 * list renders has to come off the list query, so the number of queries a
 * page costs stays flat however many rows it shows. Each test renders the
 * same table at two sizes and expects the same count both times.
 */
beforeEach(function () {
    actingAsCrmAdmin();
});

function queriesToRender(string $page): int
{
    // Render once unmeasured: the first render also pays one-off lookups
    // (settings, permissions) that would otherwise skew the comparison.
    livewire($page)->assertSuccessful();

    $count = 0;
    DB::listen(function () use (&$count): void {
        $count++;
    });

    livewire($page)->assertSuccessful();

    return $count;
}

function seedPeople(int $n): void
{
    for ($i = 0; $i < $n; $i++) {
        $person = Person::create([
            'external_id' => (string) Str::uuid(),
            'first_name' => 'Person',
            'last_name' => (string) $i,
        ]);
        $person->emails()->create(['address' => "p{$i}@example.com", 'primary' => true, 'type' => 'work']);
        $person->phones()->create(['number' => "555-000{$i}", 'primary' => true, 'type' => 'work']);
        Task::create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Call back',
            'taskable_type' => Person::class,
            'taskable_id' => $person->id,
            'due_at' => Carbon::now()->addDays($i + 1),
        ]);
    }
}

it('renders the people table in a fixed number of queries', function () {
    seedPeople(2);
    $small = queriesToRender(ListPeople::class);

    seedPeople(5);
    $large = queriesToRender(ListPeople::class);

    expect($large)->toBe($small);
});

it('renders the organizations table in a fixed number of queries', function () {
    $seed = function (int $n): void {
        for ($i = 0; $i < $n; $i++) {
            $organization = Organization::create(['external_id' => (string) Str::uuid(), 'name' => "Org {$i}"]);
            Task::create([
                'external_id' => (string) Str::uuid(),
                'name' => 'Follow up',
                'taskable_type' => Organization::class,
                'taskable_id' => $organization->id,
                'due_at' => Carbon::now()->addDay(),
            ]);
        }
    };

    $seed(2);
    $small = queriesToRender(ListOrganizations::class);

    $seed(5);
    $large = queriesToRender(ListOrganizations::class);

    expect($large)->toBe($small);
});

it('renders the products table in a fixed number of queries', function () {
    $seed = function (int $n): void {
        for ($i = 0; $i < $n; $i++) {
            $product = Product::create(['external_id' => (string) Str::uuid(), 'name' => "Widget {$i}"]);
            ProductPrice::create([
                'external_id' => (string) Str::uuid(),
                'product_id' => $product->id,
                'unit_price' => 2500,
                'currency' => 'USD',
            ]);
        }
    };

    $seed(2);
    $small = queriesToRender(ListProducts::class);

    $seed(5);
    $large = queriesToRender(ListProducts::class);

    expect($large)->toBe($small);
});

it('renders the deliveries table in a fixed number of queries', function () {
    $seed = function (int $n): void {
        for ($i = 0; $i < $n; $i++) {
            $delivery = Delivery::create(['external_id' => (string) Str::uuid()]);
            Address::create([
                'external_id' => (string) Str::uuid(),
                'addressable_type' => Delivery::class,
                'addressable_id' => $delivery->id,
                'address_type_id' => 6,
                'line1' => "{$i} Shipping St",
                'city' => 'Sydney',
            ]);
        }
    };

    $seed(2);
    $small = queriesToRender(ListDeliveries::class);

    $seed(5);
    $large = queriesToRender(ListDeliveries::class);

    expect($large)->toBe($small);
});

it('carries next activity on the list query and still answers for a bare record', function () {
    $person = Person::create(['external_id' => (string) Str::uuid(), 'first_name' => 'Ada']);
    $due = Carbon::now()->addHours(3)->startOfSecond();

    Task::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Done already',
        'taskable_type' => Person::class,
        'taskable_id' => $person->id,
        'due_at' => Carbon::now()->addHour(),
        'completed_at' => Carbon::now(),
    ]);
    Task::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Next',
        'taskable_type' => Person::class,
        'taskable_id' => $person->id,
        'due_at' => $due,
    ]);

    $listed = NextActivity::eagerLoad(Person::query())->whereKey($person->id)->first();

    expect($listed->getAttributes())->toHaveKey(NextActivity::ATTRIBUTE);
    expect(Carbon::parse(NextActivity::for($listed))->equalTo($due))->toBeTrue();
    expect(Carbon::parse(NextActivity::for($person->fresh()))->equalTo($due))->toBeTrue();
});
