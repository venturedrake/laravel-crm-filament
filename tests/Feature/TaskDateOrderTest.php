<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\Task;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmTasksRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\TasksRelationManager;
use VentureDrake\LaravelCrmFilament\Resources\Leads\Pages\EditLead;
use VentureDrake\LaravelCrmFilament\Resources\People\Pages\ViewPerson;
use VentureDrake\LaravelCrmFilament\Resources\Tasks\Pages\CreateTask;
use VentureDrake\LaravelCrmFilament\Resources\Tasks\Pages\EditTask;

use function Pest\Livewire\livewire;

/**
 * Regression for #8: a task saved with its due date before its start date.
 *
 * Every task form mirrors core 2.7.0's rule: `due_at` must be on or after
 * `start_at`, but only when a start was entered. A due-only task (the common
 * case) and a start-only task both stay valid.
 */
beforeEach(function () {
    $this->user = actingAsCrmAdmin();
});

function taskDateOrderPanel(Person $person): mixed
{
    return livewire(CrmTasksRelationManager::class, [
        'ownerRecord' => $person,
        'pageClass' => ViewPerson::class,
    ]);
}

function taskDateOrderPerson(): Person
{
    return Person::create([
        'external_id' => (string) Str::uuid(),
        'first_name' => 'Date',
        'last_name' => 'Order',
    ]);
}

describe('the task resource', function () {
    it('refuses a due date before the start on create', function () {
        livewire(CreateTask::class)
            ->fillForm([
                'name' => 'Reversed',
                'start_at' => '2026-11-19 10:00:00',
                'due_at' => '2026-11-18 10:00:00',
            ])
            ->call('create')
            ->assertHasFormErrors(['due_at']);

        expect(Task::query()->count())->toBe(0);
    });

    it('refuses a due date before the start on edit', function () {
        $task = Task::create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Valid task',
            'start_at' => '2026-11-18 10:00:00',
            'due_at' => '2026-11-19 10:00:00',
            'user_owner_id' => $this->user->id,
        ]);

        livewire(EditTask::class, ['record' => $task->external_id])
            ->fillForm([
                'start_at' => '2026-11-19 10:00:00',
                'due_at' => '2026-11-18 10:00:00',
            ])
            ->call('save')
            ->assertHasFormErrors(['due_at']);

        $task->refresh();

        expect($task->start_at->toDateTimeString())->toBe('2026-11-18 10:00:00')
            ->and($task->due_at->toDateTimeString())->toBe('2026-11-19 10:00:00');
    });

    it('saves a valid pair of dates', function (?string $start, ?string $due) {
        livewire(CreateTask::class)
            ->fillForm([
                'name' => 'Valid',
                'start_at' => $start,
                'due_at' => $due,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $task = Task::query()->sole();

        expect($task->start_at?->toDateTimeString())->toBe($start)
            ->and($task->due_at?->toDateTimeString())->toBe($due);
    })->with('validTaskDatePairs');
});

describe('the inline task form', function () {
    it('refuses a due date before the start on create', function () {
        $person = taskDateOrderPerson();

        taskDateOrderPanel($person)
            ->set('data.name', 'Reversed')
            ->set('data.start_at', '2026-11-19 10:00:00')
            ->set('data.due_at', '2026-11-18 10:00:00')
            ->call('createTask')
            ->assertHasErrors(['data.due_at']);

        expect(Task::query()->count())->toBe(0);
    });

    it('refuses a due date before the start on update', function () {
        $person = taskDateOrderPerson();

        $task = $person->tasks()->create([
            'name' => 'Valid task',
            'start_at' => '2026-11-18 10:00:00',
            'due_at' => '2026-11-19 10:00:00',
            'user_owner_id' => $this->user->id,
            'user_created_id' => $this->user->id,
        ]);

        taskDateOrderPanel($person)
            ->call('editTask', $task->id)
            ->set('data.start_at', '2026-11-19 10:00:00')
            ->set('data.due_at', '2026-11-18 10:00:00')
            ->call('updateTask')
            ->assertHasErrors(['data.due_at'])
            ->assertSet('editingId', (int) $task->id);

        $task->refresh();

        expect($task->start_at->toDateTimeString())->toBe('2026-11-18 10:00:00')
            ->and($task->due_at->toDateTimeString())->toBe('2026-11-19 10:00:00');
    });

    it('saves a valid pair of dates', function (?string $start, ?string $due) {
        $person = taskDateOrderPerson();

        taskDateOrderPanel($person)
            ->set('data.name', 'Valid')
            ->set('data.start_at', $start)
            ->set('data.due_at', $due)
            ->call('createTask')
            ->assertHasNoErrors();

        $task = Task::query()->sole();

        expect($task->start_at?->toDateTimeString())->toBe($start)
            ->and($task->due_at?->toDateTimeString())->toBe($due);
    })->with('validTaskDatePairs');
});

it('refuses a due date before the start in the table-style tasks manager', function () {
    $lead = Lead::create(['external_id' => (string) Str::uuid(), 'title' => 'Engine', 'currency' => 'USD']);

    // On an edit page, where the table manager isn't read-only.
    livewire(TasksRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => EditLead::class])
        ->callAction(TestAction::make('create')->table(), data: [
            'name' => 'Reversed',
            'start_at' => '2026-11-19 10:00:00',
            'due_at' => '2026-11-18 10:00:00',
        ])
        ->assertHasActionErrors(['due_at']);

    expect(Task::query()->count())->toBe(0);
});

dataset('validTaskDatePairs', [
    'due after start' => ['2026-11-18 10:00:00', '2026-11-19 10:00:00'],
    'due equal to start' => ['2026-11-18 10:00:00', '2026-11-18 10:00:00'],
    'due with no start' => [null, '2026-11-18 10:00:00'],
    'start with no due' => ['2026-11-18 10:00:00', null],
]);
