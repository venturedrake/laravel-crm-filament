<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\Task;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmTasksRelationManager;
use VentureDrake\LaravelCrmFilament\Resources\Leads\Pages\ViewLead;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\Pages\ViewOrganization;
use VentureDrake\LaravelCrmFilament\Resources\People\Pages\ViewPerson;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * Regression for #7: the inline task form's owner / assignee Selects use
 * ->relationship(), which needs a model on the schema. Without one, rendering
 * the Tasks tab died with "Call to a member function hasAttribute() on null".
 *
 * These tests mount the real relation manager (no subclass), so the actual
 * $this->form is built, rendered, filled and validated.
 */
beforeEach(function () {
    RoleSeeder::seed();
});

function crmTasksFormUser(): User
{
    $user = User::create([
        'name' => 'Tasks Form Tester',
        'email' => 'tasks-form-' . Str::random(8) . '@example.com',
        'password' => bcrypt('secret'),
    ]);

    foreach (['view', 'create', 'edit', 'delete'] as $action) {
        foreach (['tasks', 'people', 'leads', 'organizations'] as $entity) {
            $user->givePermissionTo("{$action} crm {$entity}");
        }
    }

    return $user->fresh();
}

function crmTasksFormPerson(): Person
{
    return Person::create([
        'external_id' => (string) Str::uuid(),
        'first_name' => 'Task',
        'last_name' => 'Owner',
    ]);
}

// CrmTasksRelationManager is shared by every owner type, so the render check
// runs against the issue's Person plus two of the other owners.
dataset('crmTasksFormOwners', [
    'Person' => [Person::class, ['first_name' => 'Task', 'last_name' => 'Owner'], ViewPerson::class],
    'Lead' => [Lead::class, ['title' => 'Task owner lead'], ViewLead::class],
    'Organization' => [Organization::class, ['name' => 'Task owner org'], ViewOrganization::class],
]);

it('renders the Tasks tab with the owner and assignee fields', function (string $model, array $attributes, string $page): void {
    $this->actingAs(crmTasksFormUser());

    $owner = $model::create(['external_id' => (string) Str::uuid(), ...$attributes]);

    livewire(CrmTasksRelationManager::class, [
        'ownerRecord' => $owner,
        'pageClass' => $page,
    ])
        ->assertSuccessful()
        ->assertFormFieldExists('user_owner_id')
        ->assertFormFieldExists('user_assigned_id');
})->with('crmTasksFormOwners');

it('creates a task on a person through the real form', function (): void {
    $user = crmTasksFormUser();
    $this->actingAs($user);

    $person = crmTasksFormPerson();

    livewire(CrmTasksRelationManager::class, [
        'ownerRecord' => $person,
        'pageClass' => ViewPerson::class,
    ])
        ->fillForm([
            'name' => 'Call back about renewal',
            'user_assigned_id' => $user->id,
        ])
        ->call('createTask')
        ->assertHasNoFormErrors();

    $task = Task::query()->where('name', 'Call back about renewal')->first();

    expect($task)->not->toBeNull()
        ->and($task->taskable_type)->toBe($person->getMorphClass())
        ->and((int) $task->taskable_id)->toBe((int) $person->id)
        ->and((int) $task->user_owner_id)->toBe((int) $user->id)
        ->and((int) $task->user_assigned_id)->toBe((int) $user->id);
});

it('edits a task on a person through the real form', function (): void {
    $user = crmTasksFormUser();
    $this->actingAs($user);

    $person = crmTasksFormPerson();

    $task = $person->tasks()->create([
        'name' => 'Original task',
        'user_owner_id' => $user->id,
        'user_assigned_id' => $user->id,
        'user_created_id' => $user->id,
    ]);

    livewire(CrmTasksRelationManager::class, [
        'ownerRecord' => $person,
        'pageClass' => ViewPerson::class,
    ])
        ->call('editTask', $task->id)
        ->assertSet('editingId', (int) $task->id)
        ->assertFormSet([
            'name' => 'Original task',
            'user_owner_id' => $user->id,
            'user_assigned_id' => $user->id,
        ])
        // set() mirrors the card's wire:model inputs. fillForm() goes through
        // Filament's test-only skipRender() path, which tries to partially
        // render the inherited table's filter form, and this view has no table.
        ->set('data.name', 'Renamed task')
        ->call('updateTask')
        ->assertHasNoFormErrors()
        ->assertSet('editingId', null);

    expect($task->fresh()->name)->toBe('Renamed task')
        ->and((int) $task->fresh()->user_assigned_id)->toBe((int) $user->id);
});
