<?php

use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Role;
use VentureDrake\LaravelCrmFilament\Resources\Users\Pages\CreateUser;
use VentureDrake\LaravelCrmFilament\Resources\Users\Pages\EditUser;
use VentureDrake\LaravelCrmFilament\Resources\Users\UserResource;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * `role_id` and `crm_team_ids` used to carry ->dehydrated(false).
 *
 * EditRecord::save() calls $this->form->getState() — which dehydrates, and so
 * Arr::forget()s a non-dehydrated key — *before* mutateFormDataBeforeSave()
 * reads $data['role_id']. So $this->roleId was always null, and afterSave()
 * fell through to $record->syncRoles([]): editing any user stripped their CRM
 * role. Silently, on every save, including a save that changed only a name.
 */
beforeEach(function () {
    RoleSeeder::seed();

    $this->admin = User::create([
        'name' => 'Role Admin',
        'email' => 'role-admin-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->admin->assignRole('Owner');

    $this->actingAs($this->admin->fresh());
});

/**
 * The role_id Select, found by walking the form tree (it sits alongside Grid
 * containers, which have no getName()).
 */
function userFormRoleSelect(): Select
{
    $schema = UserResource::form(Schema::make(livewire(CreateUser::class)->instance()));

    foreach ($schema->getFlatComponents(withHidden: true) as $component) {
        if ($component instanceof Select && $component->getName() === 'role_id') {
            return $component;
        }
    }

    throw new RuntimeException('role_id select not found on the user form.');
}

it('keeps the role when only the name is edited', function () {
    $user = User::create([
        'name' => 'Has A Role',
        'email' => 'has-a-role-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $user->assignRole('Manager');

    livewire(EditUser::class, ['record' => $user->getKey()])
        ->fillForm(['name' => 'Renamed Only'])
        ->call('save')
        ->assertHasNoFormErrors();

    $user->refresh();

    expect($user->name)->toBe('Renamed Only')
        ->and($user->roles->pluck('name')->all())->toBe(['Manager']);
});

it('applies a role change made on the edit form', function () {
    $user = User::create([
        'name' => 'Promote Me',
        'email' => 'promote-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $user->assignRole('Employee');

    livewire(EditUser::class, ['record' => $user->getKey()])
        ->fillForm(['role_id' => Role::findByName('Manager')->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->fresh()->roles->pluck('name')->all())->toBe(['Manager']);
});

it('assigns the chosen role on create', function () {
    livewire(CreateUser::class)
        ->fillForm([
            'name' => 'Freshly Created',
            'email' => 'freshly-created@example.com',
            'password' => 'correct-horse',
            'role_id' => Role::findByName('Manager')->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = User::where('email', 'freshly-created@example.com')->first();

    expect($created)->not->toBeNull()
        ->and($created->roles->pluck('name')->all())->toBe(['Manager']);
});

it('rejects a role the caller may not hand out, on create and on edit', function () {
    // A Manager may not mint or promote to Owner. AssignableRole rejects the
    // payload, and the assignment site re-checks — core does both deliberately,
    // so a rejected escalation leaves no orphaned user burning the email index.
    $manager = User::create([
        'name' => 'Manager Actor',
        'email' => 'manager-actor-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $manager->assignRole('Manager');
    $manager->givePermissionTo('create crm users');
    $manager->givePermissionTo('edit crm users');

    $this->actingAs($manager->fresh());

    livewire(CreateUser::class)
        ->fillForm([
            'name' => 'Would Be Owner',
            'email' => 'would-be-owner@example.com',
            'password' => 'correct-horse',
            'role_id' => Role::findByName('Owner')->id,
        ])
        ->call('create')
        ->assertHasFormErrors(['role_id']);

    expect(User::where('email', 'would-be-owner@example.com')->exists())->toBeFalse();

    $target = User::create([
        'name' => 'Target',
        'email' => 'target-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $target->assignRole('Employee');

    livewire(EditUser::class, ['record' => $target->getKey()])
        ->fillForm(['role_id' => Role::findByName('Owner')->id])
        ->call('save')
        ->assertHasFormErrors(['role_id']);

    expect($target->fresh()->roles->pluck('name')->all())->toBe(['Employee']);
});

it('never offers Owner in the role dropdown to a non-Owner', function () {
    $manager = User::create([
        'name' => 'Dropdown Manager',
        'email' => 'dropdown-manager-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $manager->assignRole('Manager');

    $this->actingAs($manager->fresh());

    $roleSelect = userFormRoleSelect();

    expect($roleSelect->getOptions())->not->toContain('Owner')
        ->and($roleSelect->getOptions())->toContain('Manager');
});

it('offers Owner to an Owner, so ownership transfer stays possible', function () {
    expect(userFormRoleSelect()->getOptions())->toContain('Owner');
});

it('dehydrates role_id, so mutateFormDataBeforeSave can still see it', function () {
    // The actual defect, asserted on the component rather than on the source:
    // a non-dehydrated key is Arr::forget'd by getState() before the mutate
    // hook runs.
    expect(userFormRoleSelect()->isDehydrated())->toBeTrue();
});
