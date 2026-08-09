<?php

use Filament\Schemas\Schema;
use VentureDrake\LaravelCrm\Models\Task;
use VentureDrake\LaravelCrmFilament\Pages\CalendarPage;
use VentureDrake\LaravelCrmFilament\Resources\Tasks\Pages\EditTask;
use VentureDrake\LaravelCrmFilament\Resources\Tasks\TaskResource;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * The permanent guard for the data-loss hazard core 2.4.0 introduced.
 *
 * `TaskService::create()` and `update()` write `'start_at' => $request->start_at`
 * unconditionally. A form without a `start_at` field submits nothing for it,
 * the service reads null, and the column is silently cleared — on every edit,
 * with no error anywhere.
 */
beforeEach(function () {
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'Task Start Tester',
        'email' => 'task-start-' . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->assignRole('Owner');

    $this->actingAs($this->user->fresh());
});

it('keeps start_at when an unrelated field is edited', function () {
    $task = Task::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Original name',
        'start_at' => now()->subDay()->startOfHour(),
        'due_at' => now()->addDay()->startOfHour(),
        'user_owner_id' => $this->user->id,
    ]);

    $startAt = $task->start_at->copy();

    livewire(EditTask::class, ['record' => $task->external_id])
        ->fillForm(['name' => 'Renamed'])
        ->call('save')
        ->assertHasNoFormErrors();

    $task->refresh();

    expect($task->name)->toBe('Renamed')
        ->and($task->start_at)->not->toBeNull()
        ->and($task->start_at->toDateTimeString())->toBe($startAt->toDateTimeString());
});

it('exposes start_at on the form, the infolist, the table and the export', function () {
    $formNames = array_map(
        fn ($component) => $component->getName(),
        TaskResource::form(Schema::make(
            livewire(EditTask::class, ['record' => Task::create([
                'external_id' => (string) Str::uuid(),
                'name' => 'Schema probe',
            ])->external_id])->instance()
        ))->getComponents(withHidden: true),
    );

    // Immediately before due_at — a task starts before it is due.
    $startIndex = array_search('start_at', $formNames, true);
    $dueIndex = array_search('due_at', $formNames, true);

    expect($startIndex)->not->toBeFalse()
        ->and($dueIndex)->toBe($startIndex + 1);

    $source = (string) file_get_contents((new ReflectionClass(TaskResource::class))->getFileName());

    expect($source)->toContain("TextEntry::make('start_at')")
        ->toContain("TextColumn::make('start_at')")
        ->toContain("'Start' => fn (\$r) => \$r->start_at")
        // defaultSort stays on due_at: changing it is a silent behaviour change
        // for every existing user and buys no parity.
        ->toContain("->defaultSort('due_at', 'asc')");
});

it('renders a task with both timestamps as a calendar span', function () {
    $task = Task::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Spanning task',
        'start_at' => now()->startOfDay()->addHours(9),
        'due_at' => now()->startOfDay()->addHours(17),
        'user_owner_id' => $this->user->id,
    ]);

    $events = livewire(CalendarPage::class)->instance()->getEventsForRange(
        now()->startOfDay()->toIso8601String(),
        now()->endOfDay()->toIso8601String(),
    );

    $event = collect($events)->firstWhere('id', 'task:' . $task->external_id);

    expect($event)->not->toBeNull()
        ->and($event['start'])->toBe($task->start_at->toIso8601String())
        ->and($event['end'])->toBe($task->due_at->toIso8601String());
});

it('leaves a task with only a due date as a single-point event', function () {
    $task = Task::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Due only',
        'due_at' => now()->startOfDay()->addHours(12),
        'user_owner_id' => $this->user->id,
    ]);

    $events = livewire(CalendarPage::class)->instance()->getEventsForRange(
        now()->startOfDay()->toIso8601String(),
        now()->endOfDay()->toIso8601String(),
    );

    $event = collect($events)->firstWhere('id', 'task:' . $task->external_id);

    expect($event['start'])->toBe($task->due_at->toIso8601String())
        ->and($event['end'])->toBeNull();
});

it('includes a task that starts before the window but is due inside it', function () {
    $task = Task::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Started last week',
        'start_at' => now()->subWeek(),
        'due_at' => now()->addHours(2),
        'user_owner_id' => $this->user->id,
    ]);

    $events = livewire(CalendarPage::class)->instance()->getEventsForRange(
        now()->startOfDay()->toIso8601String(),
        now()->endOfDay()->toIso8601String(),
    );

    expect(collect($events)->pluck('id'))->toContain('task:' . $task->external_id);
});

it('shifts both timestamps by the same delta when a spanning task is dragged', function () {
    $task = Task::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Dragged',
        'start_at' => now()->startOfDay()->addHours(9),
        'due_at' => now()->startOfDay()->addHours(17),
        'user_owner_id' => $this->user->id,
    ]);

    $newStart = $task->start_at->copy()->addDays(2);

    livewire(CalendarPage::class)
        ->instance()
        ->moveEvent($task->external_id, 'task', $newStart->toDateTimeString());

    $task->refresh();

    // The 8-hour span survives the move rather than collapsing onto the drop.
    expect($task->start_at->toDateTimeString())->toBe($newStart->toDateTimeString())
        ->and($task->start_at->diffInHours($task->due_at))->toBe(8.0);
});

it('still moves due_at alone when the task has no start_at', function () {
    $task = Task::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Due only drag',
        'due_at' => now()->startOfDay()->addHours(12),
        'user_owner_id' => $this->user->id,
    ]);

    $newDue = $task->due_at->copy()->addDay();

    livewire(CalendarPage::class)
        ->instance()
        ->moveEvent($task->external_id, 'task', $newDue->toDateTimeString());

    $task->refresh();

    expect($task->due_at->toDateTimeString())->toBe($newDue->toDateTimeString())
        ->and($task->start_at)->toBeNull();
});
