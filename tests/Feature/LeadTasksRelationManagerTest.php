<?php

use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Activity;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\Task;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmTasksRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\TasksRelationManager;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

/**
 * Hot-patch subclass that bypasses $this->form->fill() / getState() (which require
 * a real Filament panel mount) and operates on $this->data directly. Mirrors the
 * pattern locked-in by CrmNotesRelationManagerTest. Re-implements the four CRUD
 * bodies without form validation so the round-trip can be exercised headless.
 */
function leadTasksFocusedRm(): CrmTasksRelationManager
{
    return new class extends CrmTasksRelationManager
    {
        public function createTask(): void
        {
            $data = $this->data ?? [];

            $task = $this->getOwnerRecord()->tasks()->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'due_at' => $data['due_at'] ?? null,
                'user_owner_id' => $data['user_owner_id'] ?? auth()->id(),
                'user_assigned_id' => $data['user_assigned_id'] ?? null,
                'user_created_id' => auth()->id(),
            ]);

            self::logCrmActivity($this->getOwnerRecord(), $task);

            $this->data = [
                'name' => null,
                'description' => null,
                'due_at' => now(),
                'user_owner_id' => auth()->id(),
                'user_assigned_id' => null,
            ];

            Notification::make()->title('Task added')->success()->send();
        }

        public function editTask(int $id): void
        {
            $task = $this->getOwnerRecord()->tasks()->whereKey($id)->first();

            if ($task === null) {
                return;
            }

            $this->editingId = (int) $task->id;
            $this->data = [
                'name' => $task->name,
                'description' => $task->description,
                'due_at' => $task->due_at,
                'user_owner_id' => $task->user_owner_id,
                'user_assigned_id' => $task->user_assigned_id,
            ];
        }

        public function updateTask(): void
        {
            if ($this->editingId === null) {
                return;
            }

            $task = $this->getOwnerRecord()->tasks()->whereKey($this->editingId)->first();

            if ($task === null) {
                return;
            }

            $data = $this->data ?? [];

            $task->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'due_at' => $data['due_at'] ?? null,
                'user_owner_id' => $data['user_owner_id'] ?? null,
                'user_assigned_id' => $data['user_assigned_id'] ?? null,
                'user_updated_id' => auth()->id(),
            ]);

            self::logCrmActivity($this->getOwnerRecord(), $task);

            $this->editingId = null;
            $this->data = [
                'name' => null,
                'description' => null,
                'due_at' => now(),
                'user_owner_id' => auth()->id(),
                'user_assigned_id' => null,
            ];

            Notification::make()->title('Task updated')->success()->send();
        }

        public function deleteTask(int $id): void
        {
            $task = $this->getOwnerRecord()->tasks()->whereKey($id)->first();

            if ($task === null) {
                return;
            }

            $task->delete();

            Notification::make()->title('Task deleted')->success()->send();
        }
    };
}

// AC (1) Inheritance.

it('extends TasksRelationManager', function () {
    expect(is_subclass_of(CrmTasksRelationManager::class, TasksRelationManager::class))->toBeTrue();
});

// AC (2) $view points at the lead-tasks template.

it('overrides the $view property to point at the lead-tasks Blade template', function () {
    $ref = new ReflectionClass(CrmTasksRelationManager::class);
    $prop = $ref->getProperty('view');
    $prop->setAccessible(true);

    expect($prop->getDeclaringClass()->getName())->toBe(CrmTasksRelationManager::class);

    $rm = $ref->newInstanceWithoutConstructor();
    expect($prop->getValue($rm))->toBe('laravel-crm-filament::crm-tasks');
});

// AC (3) form() returns name/description/due_at + owner/assigned grid.

it('returns the expected form schema with name, description, due_at, owner, assigned', function () {
    $rm = (new ReflectionClass(CrmTasksRelationManager::class))->newInstanceWithoutConstructor();
    $schema = $rm->form(Schema::make($rm));

    expect($schema->getStatePath())->toBe('data');

    $components = $schema->getComponents();
    expect($components[0])->toBeInstanceOf(Forms\Components\TextInput::class);
    expect($components[0]->getName())->toBe('name');
    expect($components[1])->toBeInstanceOf(Forms\Components\Textarea::class);
    expect($components[1]->getName())->toBe('description');
    expect($components[2])->toBeInstanceOf(Forms\Components\DateTimePicker::class);
    expect($components[2]->getName())->toBe('due_at');
    expect($components[3])->toBeInstanceOf(Grid::class);

    // Walk into the Grid to confirm the owner + assigned selects are inside it.
    $gridProp = new ReflectionProperty(Grid::class, 'childComponents');
    $gridProp->setAccessible(true);
    $gridChildren = $gridProp->getValue($components[3]);
    $children = $gridChildren['default'] ?? $gridChildren;

    $childNames = array_values(array_map(fn ($c) => $c->getName(), $children));
    expect($childNames)->toBe(['user_owner_id', 'user_assigned_id']);
});

it('inherits the parent table configuration (columns and actions)', function () {
    $ref = new ReflectionClass(CrmTasksRelationManager::class);

    expect($ref->hasMethod('table'))->toBeTrue();

    // US-009: the RollsUpRelatedActivity concern composes a table() that
    // delegates to the parent and appends the "Related" badge column, so the
    // declaring class is now the Crm* subclass the trait is used by.
    expect($ref->getMethod('table')->getDeclaringClass()->getName())
        ->toBe(CrmTasksRelationManager::class);
    expect(($ref->getMethod('table')->getFileName()))
        ->toContain('RollsUpRelatedActivity.php');
});

it('inherits the parent relationship binding', function () {
    $ref = new ReflectionClass(CrmTasksRelationManager::class);
    $prop = $ref->getProperty('relationship');
    $prop->setAccessible(true);

    expect($prop->getValue())->toBe('tasks');
});

// AC (4) CRUD round-trip against a real Lead owner record.

it('createTask() persists a Task with user_created_id and writes an activity row', function () {
    $rm = leadTasksFocusedRm();

    $user = User::create([
        'name' => 'Focused Task Author',
        'email' => 'focused-task-author-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    auth()->login($user);

    $lead = Lead::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Focused lead — create task',
    ]);

    $rm->ownerRecord = $lead->fresh();
    $rm->data = [
        'name' => 'Focused inline task',
        'description' => 'Body text',
        'due_at' => now()->addDay()->setSecond(0),
        'user_owner_id' => $user->id,
        'user_assigned_id' => null,
    ];

    $rm->createTask();

    $task = Task::query()->where('name', 'Focused inline task')->first();
    expect($task)->not->toBeNull();
    expect($task->taskable_type)->toBe($lead->getMorphClass());
    expect((int) $task->taskable_id)->toBe($lead->id);
    expect((int) $task->user_created_id)->toBe($user->id);
    expect((int) $task->user_owner_id)->toBe($user->id);

    expect(Activity::query()
        ->where('timelineable_type', $lead->getMorphClass())
        ->where('timelineable_id', $lead->id)
        ->where('recordable_type', $task->getMorphClass())
        ->where('recordable_id', $task->id)
        ->exists())->toBeTrue();
});

it('updateTask() persists edits via the owner relation and writes an activity row', function () {
    $rm = leadTasksFocusedRm();

    $user = User::create([
        'name' => 'Focused Task Updater',
        'email' => 'focused-task-updater-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    auth()->login($user);

    $lead = Lead::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Focused lead — update task',
    ]);

    $task = $lead->tasks()->create([
        'name' => 'Name before edit',
        'description' => 'Body before edit',
        'due_at' => now()->subDay()->setSecond(0),
        'user_created_id' => $user->id,
    ]);

    $rm->ownerRecord = $lead->fresh();
    $rm->editingId = (int) $task->id;
    $rm->data = [
        'name' => 'Name after edit',
        'description' => 'Body after edit',
        'due_at' => now()->setSecond(0),
        'user_owner_id' => $user->id,
        'user_assigned_id' => null,
    ];

    $rm->updateTask();

    $reloaded = Task::find($task->id);
    expect($reloaded->name)->toBe('Name after edit');
    expect($reloaded->description)->toBe('Body after edit');
    expect($reloaded->taskable_type)->toBe($lead->getMorphClass());
    expect((int) $reloaded->taskable_id)->toBe($lead->id);
    expect((int) $reloaded->user_updated_id)->toBe($user->id);

    expect(Activity::query()
        ->where('timelineable_type', $lead->getMorphClass())
        ->where('timelineable_id', $lead->id)
        ->where('recordable_type', $task->getMorphClass())
        ->where('recordable_id', $task->id)
        ->exists())->toBeTrue();
});

it('deleteTask() soft-deletes the task via the owner relation', function () {
    $rm = leadTasksFocusedRm();

    $user = User::create([
        'name' => 'Focused Task Deleter',
        'email' => 'focused-task-deleter-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    auth()->login($user);

    $lead = Lead::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Focused lead — delete task',
    ]);

    $task = $lead->tasks()->create([
        'name' => 'To be soft-deleted',
        'due_at' => now(),
        'user_created_id' => $user->id,
    ]);

    $rm->ownerRecord = $lead->fresh();

    $rm->deleteTask((int) $task->id);

    expect(Task::query()->find($task->id))->toBeNull();
    expect(Task::withTrashed()->find($task->id))->not->toBeNull();
    expect(Task::withTrashed()->find($task->id)->deleted_at)->not->toBeNull();
});

// AC (5) Blade source assertions.

it('the lead-tasks Blade view contains the expected structural markers', function () {
    $bladePath = dirname(__DIR__, 2) . '/resources/views/crm-tasks.blade.php';
    expect(file_exists($bladePath))->toBeTrue();

    $blade = file_get_contents($bladePath);

    // Add-task form wired to createTask with the inline state bindings.
    expect($blade)->toContain('@if ($editingId === null)');
    expect($blade)->toContain('wire:submit="createTask"');
    expect($blade)->toContain('wire:model="data.name"');
    expect($blade)->toContain('wire:model="data.description"');
    expect($blade)->toContain('wire:model="data.due_at"');

    // Section heading uses the new translation key.
    expect($blade)->toContain('laravel-crm-filament::labels.sections.add_task');

    // Tasks loop (cards) sorted by created_at desc.
    // US-009: rows now come from the RollsUpRelatedActivity concern (still
    // newest-first) so the `show_related_activity` setting is honoured.
    expect($blade)->toContain('$this->relatedActivityRows()');
    expect($blade)->toContain('@forelse');
    expect($blade)->toContain('@empty');

    // Three-dot dropdown wired to editTask / deleteTask per row.
    expect($blade)->toContain('x-data="{ open: false }"');
    expect($blade)->toContain('crm-card-dropdown');
    expect($blade)->toContain('wire:click="editTask({{ $task->id }})"');
    expect($blade)->toContain('wire:click="deleteTask({{ $task->id }})"');

    // Inline edit swap and cancel button.
    expect($blade)->toContain('@if ($editingId === $task->id)');
    expect($blade)->toContain('wire:submit="updateTask"');
    expect($blade)->toContain('wire:click="cancelEdit"');

    // Shared lead-card-styles partial.
    expect($blade)->toContain("@include('laravel-crm-filament::partials.crm-card-styles')");
    expect($blade)->not->toContain('@once');

    // Empty state.
    expect($blade)->toContain('No tasks yet');

    // Partial extension confirms the shared selector covers crm-card-area-tasks.
    $partialPath = dirname(__DIR__, 2) . '/resources/views/partials/crm-card-styles.blade.php';
    expect(file_exists($partialPath))->toBeTrue();

    $partial = file_get_contents($partialPath);
    expect($partial)->toContain('.crm-card-area-tasks');
    expect($partial)->toContain('html.dark .crm-card-area-tasks');
});
