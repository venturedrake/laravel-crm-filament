<?php

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Illuminate\Support\Carbon;
use VentureDrake\LaravelCrm\Models\Task;
use VentureDrake\LaravelCrmFilament\Resources\Tasks\Pages\ListTasks;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

beforeEach(function () {
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'Task Parity Tester',
        'email' => 'task-parity-tester' . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->assignRole('Admin');
    $this->actingAs($this->user);
});

it('renders the AC-named 8-column list in the prescribed order', function () {
    $names = array_keys(livewire(ListTasks::class)->instance()->getTable()->getColumns());

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

it('status column source declares badge()', function () {
    $source = file_get_contents(__DIR__ . '/../../src/Resources/Tasks/TaskResource.php');

    $start = strpos($source, "TextColumn::make('status')");
    $end = strpos($source, "TextColumn::make('name')", $start);
    $block = substr($source, $start, $end - $start);

    expect($block)->toContain('->badge()');
});

it('status column source declares the Completed/Pending state closure and color map', function () {
    $source = file_get_contents(__DIR__ . '/../../src/Resources/Tasks/TaskResource.php');

    $start = strpos($source, "TextColumn::make('status')");
    $end = strpos($source, "TextColumn::make('name')", $start);
    $block = substr($source, $start, $end - $start);

    expect($block)->toContain('$record->completed_at ? \'Completed\' : \'Pending\'');
    expect($block)->toContain("'Completed' => 'success',");
    expect($block)->toContain("'Pending' => 'warning',");
});

it('description column source uses tooltip modifier and does NOT wrap', function () {
    $source = file_get_contents(__DIR__ . '/../../src/Resources/Tasks/TaskResource.php');

    $start = strpos($source, "TextColumn::make('description')");
    $end = strpos($source, "TextColumn::make('due_at')", $start);
    $block = substr($source, $start, $end - $start);

    expect($block)->toContain('->tooltip(');
    expect($block)->not->toContain('->wrap()');
});

it('created_at column source uses since() for relative time rendering', function () {
    $source = file_get_contents(__DIR__ . '/../../src/Resources/Tasks/TaskResource.php');

    $start = strpos($source, "TextColumn::make('created_at')");
    $end = strpos($source, "TextColumn::make('status')", $start);
    $block = substr($source, $start, $end - $start);

    expect($block)->toContain('->since()');
});

it('due_at column source uses since() for relative time rendering', function () {
    $source = file_get_contents(__DIR__ . '/../../src/Resources/Tasks/TaskResource.php');

    $start = strpos($source, "TextColumn::make('due_at')");
    $end = strpos($source, "TextColumn::make('ownerUser.name')", $start);
    $block = substr($source, $start, $end - $start);

    expect($block)->toContain('->since()');
});

it('ownerUser.name placeholder resolves to Unallocated', function () {
    $columns = livewire(ListTasks::class)->instance()->getTable()->getColumns();

    expect($columns['ownerUser.name']->getPlaceholder())->toBe('Unallocated');
});

it('preserves the default sort of due_at asc', function () {
    $source = file_get_contents(__DIR__ . '/../../src/Resources/Tasks/TaskResource.php');

    expect($source)->toContain("->defaultSort('due_at', 'asc')");
});

it('exposes row actions in the order [markComplete, view, edit, delete]', function () {
    $actions = array_values(livewire(ListTasks::class)->instance()->getTable()->getRecordActions());

    expect($actions)->toHaveCount(4);
    expect($actions[0])->toBeInstanceOf(Action::class);
    expect($actions[0]->getName())->toBe('markComplete');
    expect($actions[1])->toBeInstanceOf(ViewAction::class);
    expect($actions[2])->toBeInstanceOf(EditAction::class);
    expect($actions[3])->toBeInstanceOf(DeleteAction::class);
    expect($actions[3]->isConfirmationRequired())->toBeTrue();
});

it('mounts ListTasks end-to-end and renders a Pending task row', function () {
    $task = Task::create([
        'name' => 'Ship US-003',
        'description' => 'Add TaskResourceTableParityTest',
        'due_at' => Carbon::now()->addDays(2),
        'user_owner_id' => $this->user->id,
        'completed_at' => null,
    ]);

    livewire(ListTasks::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$task])
        ->assertSee('Pending');
});
