<?php

use Filament\Forms\Components\Select;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as SpatieRole;
use VentureDrake\LaravelCrm\Models\Role;
use VentureDrake\LaravelCrmFilament\Resources\Users\Pages\ListUsers;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * Core's user index filters on role; the panel's did not, so an admin looking
 * for "who are the managers?" had to read the whole list.
 */
beforeEach(function () {
    RoleSeeder::seed();

    $this->admin = User::create([
        'name' => 'Role Filter Admin',
        'email' => 'role-filter-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->admin->assignRole('Owner');
    $this->actingAs($this->admin->fresh());

    $this->manager = User::create([
        'name' => 'A Manager',
        'email' => 'manager-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->manager->assignRole('Manager');

    $this->employee = User::create([
        'name' => 'An Employee',
        'email' => 'employee-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->employee->assignRole('Employee');
});

function userRoleFilter(): SelectFilter
{
    $filters = livewire(ListUsers::class)->instance()->getTable()->getFilters();

    expect($filters)->toHaveKey('roles');

    return $filters['roles'];
}

/**
 * The role names the filter dropdown actually offers.
 *
 * A relationship-backed SelectFilter resolves its options lazily through the
 * Select it builds in getFormField(), so SelectFilter::getOptions() (the
 * static-array path) returns an empty array and tells you nothing. The Select
 * also needs its container, so it has to come off the mounted filters form
 * rather than from a detached getFormField() call.
 *
 * @return array<int, string>
 */
function userRoleFilterOptions(): array
{
    $form = livewire(ListUsers::class)->instance()->getTableFiltersForm();

    foreach ($form->getFlatComponents(withHidden: true) as $component) {
        // The state path is tableDeferredFilters.* when the table defers
        // filters, tableFilters.* otherwise; match on the suffix.
        if ($component instanceof Select && str_ends_with($component->getStatePath(), '.roles.values')) {
            return array_values($component->getOptions());
        }
    }

    throw new RuntimeException('The roles filter Select was not found on the filters form.');
}

it('registers a multiple role filter alongside crm_access', function () {
    $filters = livewire(ListUsers::class)->instance()->getTable()->getFilters();

    expect(array_keys($filters))->toBe(['roles', 'crm_access']);
    expect($filters['roles'])->toBeInstanceOf(SelectFilter::class)
        ->and($filters['roles']->isMultiple())->toBeTrue()
        ->and($filters['roles']->getRelationshipName())->toBe('roles');
});

it('narrows the list to users holding the selected role', function () {
    livewire(ListUsers::class)
        ->filterTable('roles', [Role::findByName('Manager')->id])
        ->assertCanSeeTableRecords([$this->manager])
        ->assertCanNotSeeTableRecords([$this->employee, $this->admin]);
});

it('accepts several roles at once', function () {
    livewire(ListUsers::class)
        ->filterTable('roles', [
            Role::findByName('Manager')->id,
            Role::findByName('Employee')->id,
        ])
        ->assertCanSeeTableRecords([$this->manager, $this->employee])
        ->assertCanNotSeeTableRecords([$this->admin]);
});

it('shows every user again once the filter is cleared', function () {
    livewire(ListUsers::class)
        ->filterTable('roles', [Role::findByName('Manager')->id])
        ->filterTable('roles', [])
        ->assertCanSeeTableRecords([$this->admin, $this->manager, $this->employee]);
});

it('offers CRM roles only, never the host application\'s own Spatie roles', function () {
    $hostRole = SpatieRole::findOrCreate('Warehouse Staff');

    $options = userRoleFilterOptions();

    expect($options)->toContain('Manager', 'Employee')
        // crm_role = 0, so it is neither offered nor selectable.
        ->and($options)->not->toContain('Warehouse Staff')
        ->and($hostRole->crm_role)->toBeFalsy();
});

it('does not match a user whose only role is a non-CRM one', function () {
    $hostRole = SpatieRole::findOrCreate('Warehouse Staff');

    $warehouse = User::create([
        'name' => 'Warehouse Only',
        'email' => 'warehouse-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $warehouse->assignRole($hostRole);

    // The crm_role = 1 clause lives inside the whereHas, so passing a non-CRM
    // role id cannot widen the result set.
    livewire(ListUsers::class)
        ->filterTable('roles', [$hostRole->id])
        ->assertCanNotSeeTableRecords([$warehouse, $this->manager]);
});

it('offers Owner to a non-Owner, because filtering is a read', function () {
    // Role::assignableBy() governs which roles a caller may hand *out*. The
    // role is already on screen in the roles.name column, so hiding Owner here
    // would only stop a Manager narrowing a list they can already see in full.
    $manager = User::create([
        'name' => 'Filtering Manager',
        'email' => 'filtering-manager-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $manager->assignRole('Manager');
    $manager->givePermissionTo('view crm users');

    $this->actingAs($manager->fresh());

    expect(userRoleFilterOptions())->toContain('Owner');
});
