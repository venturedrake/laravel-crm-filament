<?php

use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Deal;
use VentureDrake\LaravelCrm\Models\Label;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\Task;
use VentureDrake\LaravelCrmFilament\Resources\People\Pages\ListPeople;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

beforeEach(function () {
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'Person Columns Tester',
        'email' => 'person-columns-tester' . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->assignRole('Admin');
    $this->actingAs($this->user);
});

/**
 * @return array<string, TextColumn>
 */
function personTableColumns(): array
{
    /** @var ListPeople $instance */
    $instance = livewire(ListPeople::class)->instance();

    $columns = $instance->getTable()->getColumns();

    $out = [];
    foreach ($columns as $col) {
        $out[$col->getName()] = $col;
    }

    return $out;
}

it('renders the 10 plan columns in the prescribed order', function () {
    $names = array_keys(personTableColumns());

    expect($names)->toBe([
        'name',
        'labels.name',
        'email',
        'phone',
        'open_deals_count',
        'lost_deals_count',
        'won_deals_count',
        'next_activity',
        'ownerUser.name',
        'created_at',
    ]);
});

it('renders the name column without SQL sortability (matches core no-SQL-column treatment)', function () {
    $cols = personTableColumns();

    expect($cols['name']->isSortable())->toBeFalse();
});

it('renders labels.name as a badge with up to 3 entries and a hex color closure', function () {
    $cols = personTableColumns();

    expect($cols['labels.name'])->not->toBeNull();

    $source = file_get_contents(__DIR__ . '/../../src/Resources/People/PersonResource.php');
    expect($source)->toContain("Tables\\Columns\\TextColumn::make('labels.name')");
    expect($source)->toContain('->badge()');
    expect($source)->toContain('->limitList(3)');
    expect($source)->toContain('->firstWhere(\'name\', $state)');
    expect($source)->toContain('->hex');
});

it('renders created_at with relative-time formatting and is toggleable', function () {
    $cols = personTableColumns();
    $created = $cols['created_at'];

    expect($created->isToggleable())->toBeTrue();

    $source = file_get_contents(__DIR__ . '/../../src/Resources/People/PersonResource.php');
    expect($source)->toContain("Tables\\Columns\\TextColumn::make('created_at')");
    expect($source)->toContain('->since()');
});

it('renders ownerUser.name with the Unallocated placeholder', function () {
    $source = file_get_contents(__DIR__ . '/../../src/Resources/People/PersonResource.php');

    expect($source)->toContain("Tables\\Columns\\TextColumn::make('ownerUser.name')");
    expect($source)->toContain('labels.misc.unallocated');
});

it('reads email and phone off the primary row of the eager-loaded collections', function () {
    $source = file_get_contents(__DIR__ . '/../../src/Resources/People/PersonResource.php');

    expect($source)->toContain("Tables\\Columns\\TextColumn::make('email')");
    expect($source)->toContain('primaryContactDetail($record, \'emails\')?->address');
    expect($source)->toContain("Tables\\Columns\\TextColumn::make('phone')");
    expect($source)->toContain('primaryContactDetail($record, \'phones\')?->number');
});

it('drops the prior first_name / last_name columns', function () {
    $names = array_keys(personTableColumns());

    expect($names)->not->toContain('first_name');
    expect($names)->not->toContain('last_name');
});

it('uses translation keys for the new column labels', function () {
    $source = file_get_contents(__DIR__ . '/../../src/Resources/People/PersonResource.php');

    foreach ([
        'labels.fields.name',
        'labels.fields.labels',
        'labels.fields.email',
        'labels.fields.phone',
        'labels.fields.open_deals',
        'labels.fields.lost_deals',
        'labels.fields.won_deals',
        'labels.fields.next_activity',
        'labels.fields.owner',
        'labels.fields.created',
        'labels.misc.unallocated',
    ] as $key) {
        expect($source)->toContain($key);
    }
});

it('matches show-page relation counts for open/lost/won deals via withCount', function () {
    $person = Person::create([
        'external_id' => (string) Str::uuid(),
        'first_name' => 'Hedy',
        'last_name' => 'Lamarr',
    ]);

    // 2 open deals (closed_at = null)
    Deal::create(['external_id' => (string) Str::uuid(), 'person_id' => $person->id, 'title' => 'Open A']);
    Deal::create(['external_id' => (string) Str::uuid(), 'person_id' => $person->id, 'title' => 'Open B']);

    // 1 won
    Deal::create([
        'external_id' => (string) Str::uuid(),
        'person_id' => $person->id,
        'title' => 'Won 1',
        'closed_at' => now(),
        'closed_status' => 'won',
    ]);

    // 3 lost
    Deal::create([
        'external_id' => (string) Str::uuid(),
        'person_id' => $person->id,
        'title' => 'Lost 1',
        'closed_at' => now(),
        'closed_status' => 'lost',
    ]);
    Deal::create([
        'external_id' => (string) Str::uuid(),
        'person_id' => $person->id,
        'title' => 'Lost 2',
        'closed_at' => now(),
        'closed_status' => 'lost',
    ]);
    Deal::create([
        'external_id' => (string) Str::uuid(),
        'person_id' => $person->id,
        'title' => 'Lost 3',
        'closed_at' => now(),
        'closed_status' => 'lost',
    ]);

    /** @var ListPeople $instance */
    $instance = livewire(ListPeople::class)->instance();
    $rows = $instance->getTable()->getQuery()->where('id', $person->id)->get();

    expect($rows)->toHaveCount(1);
    expect((int) $rows[0]->open_deals_count)->toBe(2);
    expect((int) $rows[0]->lost_deals_count)->toBe(3);
    expect((int) $rows[0]->won_deals_count)->toBe(1);

    // Cross-check against the live relation counts the show page would compute.
    expect($person->deals()->whereNull('closed_at')->count())->toBe(2);
    expect($person->deals()->where('closed_status', 'lost')->count())->toBe(3);
    expect($person->deals()->where('closed_status', 'won')->count())->toBe(1);
});

it('computes next_activity from the soonest incomplete future task due_at', function () {
    $person = Person::create([
        'external_id' => (string) Str::uuid(),
        'first_name' => 'Mae',
        'last_name' => 'Jemison',
    ]);

    // Past task - excluded by where('due_at', '>=', now())
    Task::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Old',
        'taskable_type' => Person::class,
        'taskable_id' => $person->id,
        'due_at' => Carbon::now()->subDay(),
    ]);

    // Completed task - excluded by whereNull('completed_at')
    Task::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Done',
        'taskable_type' => Person::class,
        'taskable_id' => $person->id,
        'due_at' => Carbon::now()->addDay(),
        'completed_at' => Carbon::now(),
    ]);

    // The actual next activity - soonest of two future-incomplete tasks
    $expectedDue = Carbon::now()->addHours(6);
    Task::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Next',
        'taskable_type' => Person::class,
        'taskable_id' => $person->id,
        'due_at' => $expectedDue,
    ]);
    Task::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Later',
        'taskable_type' => Person::class,
        'taskable_id' => $person->id,
        'due_at' => Carbon::now()->addDays(3),
    ]);

    $cols = personTableColumns();

    $ref = new ReflectionProperty(
        TextColumn::class,
        'getStateUsing'
    );
    $ref->setAccessible(true);
    $closure = $ref->getValue($cols['next_activity']);

    expect($closure)->toBeInstanceOf(Closure::class);

    $value = $closure($person);
    expect($value)->not->toBeNull();
    expect((string) $value)->toBe((string) $expectedDue);
});

it('renders labels.name color via the hex closure when a label has hex', function () {
    $person = Person::create([
        'external_id' => (string) Str::uuid(),
        'first_name' => 'Rosalind',
        'last_name' => 'Franklin',
    ]);

    $label = Label::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'VIP',
        'hex' => 'aabbcc',
    ]);

    $person->labels()->attach($label->id);

    $cols = personTableColumns();
    $colorFn = $cols['labels.name']->getColor('VIP', $person);

    expect($colorFn)->toBe('#aabbcc');
});

it('falls back to gray for the labels.name color when no hex is set', function () {
    $person = Person::create([
        'external_id' => (string) Str::uuid(),
        'first_name' => 'Jane',
        'last_name' => 'Goodall',
    ]);

    $label = Label::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Plain',
    ]);

    $person->labels()->attach($label->id);

    $cols = personTableColumns();
    $color = $cols['labels.name']->getColor('Plain', $person);

    expect($color)->toBe('gray');
});
