<?php

use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Carbon;
use VentureDrake\LaravelCrm\Models\Task;
use VentureDrake\LaravelCrmFilament\Resources\Tasks\Pages\ListTasks;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

beforeEach(function () {
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'Task Columns Tester',
        'email' => 'task-columns-tester' . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->assignRole('Admin');
    $this->actingAs($this->user);
});

/**
 * @return array<string, TextColumn>
 */
function taskTableColumns(): array
{
    /** @var ListTasks $instance */
    $instance = livewire(ListTasks::class)->instance();

    $columns = $instance->getTable()->getColumns();

    $out = [];
    foreach ($columns as $col) {
        $out[$col->getName()] = $col;
    }

    return $out;
}

it('renders the 8 AC-named columns in the prescribed order', function () {
    $names = array_keys(taskTableColumns());

    expect($names)->toBe([
        'created_at',
        'status',
        'name',
        'description',
        // start_at joins the table as of core 2.4.0 — toggled off by default,
        // but getColumns() lists it. See TaskStartAtTest.
        'start_at',
        'due_at',
        'ownerUser.name',
        'assignedToUser.name',
    ]);
});

it('renders created_at with since() + sortable() + toggleable() modifiers', function () {
    $cols = taskTableColumns();

    expect($cols['created_at']->isSortable())->toBeTrue();
    expect($cols['created_at']->isToggleable())->toBeTrue();

    $source = file_get_contents(__DIR__ . '/../../src/Resources/Tasks/TaskResource.php');
    expect($source)->toContain("TextColumn::make('created_at')");
    expect($source)->toContain('->since()');
});

it('synthesises a status column driven by completed_at with badge + color map + custom sort query', function () {
    $cols = taskTableColumns();

    expect($cols['status'])->toBeInstanceOf(TextColumn::class);
    expect($cols['status']->isSortable())->toBeTrue();

    $pending = new Task(['completed_at' => null]);
    $completed = new Task(['completed_at' => now()]);

    $ref = new ReflectionProperty(TextColumn::class, 'getStateUsing');
    $ref->setAccessible(true);
    $stateClosure = $ref->getValue($cols['status']);

    expect($stateClosure)->toBeInstanceOf(Closure::class);
    expect($stateClosure($pending))->toBe('Pending');
    expect($stateClosure($completed))->toBe('Completed');

    expect($cols['status']->getColor('Pending', $pending))->toBe('warning');
    expect($cols['status']->getColor('Completed', $completed))->toBe('success');

    $source = file_get_contents(__DIR__ . '/../../src/Resources/Tasks/TaskResource.php');
    expect($source)->toContain("TextColumn::make('status')");
    expect($source)->toContain('->badge()');
    expect($source)->toContain('->color(fn (string $state): string => match ($state) {');
    expect($source)->toContain("'Completed' => 'success',");
    expect($source)->toContain("'Pending' => 'warning',");
    expect($source)->toContain("->sortable(query: fn (Builder \$query, string \$direction): Builder => \$query->orderBy('completed_at', \$direction))");
});

it('renders name with searchable + sortable + limit(60)', function () {
    $cols = taskTableColumns();

    expect($cols['name']->isSortable())->toBeTrue();

    $source = file_get_contents(__DIR__ . '/../../src/Resources/Tasks/TaskResource.php');
    expect($source)->toContain("TextColumn::make('name')");
    expect($source)->toContain('->searchable()');
    expect($source)->toContain('->limit(60)');
});

it('renders description with limit + tooltip closure + toggleable (no wrap — single-line)', function () {
    $cols = taskTableColumns();

    expect($cols['description'])->toBeInstanceOf(TextColumn::class);
    expect($cols['description']->isToggleable())->toBeTrue();

    $source = file_get_contents(__DIR__ . '/../../src/Resources/Tasks/TaskResource.php');
    // Scope wrap-absence assertion to the description column block only so
    // an unrelated column's ->wrap() (if ever added) doesn't cause a false
    // negative here.
    $start = strpos($source, "TextColumn::make('description')");
    $end = strpos($source, "TextColumn::make('due_at')", $start);
    $block = substr($source, $start, $end - $start);

    expect($source)->toContain("TextColumn::make('description')");
    expect($block)->not->toContain('->wrap()');
    expect($source)->toContain('->tooltip(fn (Task $record): ?string => $record->description)');
});

it('renders due_at with since() + tooltip datetime format + sortable + em-dash placeholder', function () {
    $cols = taskTableColumns();

    expect($cols['due_at']->isSortable())->toBeTrue();
    expect($cols['due_at']->getPlaceholder())->toBe('—');

    $source = file_get_contents(__DIR__ . '/../../src/Resources/Tasks/TaskResource.php');
    expect($source)->toContain("TextColumn::make('due_at')");
    expect($source)->toContain("->tooltip(fn (Task \$record): ?string => optional(\$record->due_at)->format('M d, Y H:i'))");
});

it('renders ownerUser.name with created_by label + toggleable + Unallocated placeholder', function () {
    $cols = taskTableColumns();

    expect($cols['ownerUser.name']->isToggleable())->toBeTrue();
    expect($cols['ownerUser.name']->getPlaceholder())->toBe('Unallocated');

    $source = file_get_contents(__DIR__ . '/../../src/Resources/Tasks/TaskResource.php');
    expect($source)->toContain("TextColumn::make('ownerUser.name')");
    expect($source)->toContain('labels.fields.created_by');
    expect($source)->toContain('labels.misc.unallocated');
});

it('renders assignedToUser.name with assigned_to label + toggleable + em-dash placeholder', function () {
    $cols = taskTableColumns();

    expect($cols['assignedToUser.name']->isToggleable())->toBeTrue();
    expect($cols['assignedToUser.name']->getPlaceholder())->toBe('—');

    $source = file_get_contents(__DIR__ . '/../../src/Resources/Tasks/TaskResource.php');
    expect($source)->toContain("TextColumn::make('assignedToUser.name')");
    expect($source)->toContain('labels.fields.assigned_to');
});

it('preserves defaultSort(due_at, asc)', function () {
    $source = file_get_contents(__DIR__ . '/../../src/Resources/Tasks/TaskResource.php');

    expect($source)->toContain("->defaultSort('due_at', 'asc')");
});

it('preserves View + Edit row actions and markComplete + DeleteBulkAction bulk actions', function () {
    $source = file_get_contents(__DIR__ . '/../../src/Resources/Tasks/TaskResource.php');

    expect($source)->toContain('Actions\ViewAction::make()');
    expect($source)->toContain('Actions\EditAction::make()');
    expect($source)->toContain("BulkAction::make('markComplete')");
    expect($source)->toContain('Actions\DeleteBulkAction::make()');
});

it('is Livewire-mountable end-to-end and renders task rows without error', function () {
    $task = Task::create([
        'name' => 'Ship US-002',
        'description' => 'Rewrite TaskResource columns',
        'due_at' => Carbon::now()->addDays(3),
        'user_owner_id' => $this->user->id,
    ]);

    livewire(ListTasks::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$task]);
});
