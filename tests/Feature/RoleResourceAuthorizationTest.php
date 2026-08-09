<?php

use Filament\Forms\Components\CheckboxList;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;
use VentureDrake\LaravelCrm\Models\Role;
use VentureDrake\LaravelCrmFilament\Resources\Roles\Pages\ListRoles;
use VentureDrake\LaravelCrmFilament\Resources\Roles\RoleResource;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * RoleResource pointed `$model` at Spatie's Role. Core registers RolePolicy
 * against VentureDrake\LaravelCrm\Models\Role only, and Gate::getPolicyFor()
 * walks child -> parent — so no policy resolved, Filament allows when it finds
 * none, and any panel user could edit roles and grant themselves every
 * permission in the system.
 */
beforeEach(function () {
    RoleSeeder::seed();
});

function roleResourceUser(array $permissions = []): User
{
    $user = User::create([
        'name' => 'Role Resource Tester',
        'email' => 'roleres-' . Str::random(8) . '@example.com',
        'password' => bcrypt('secret'),
    ]);

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

it('binds core\'s Role, which is the class RolePolicy is registered for', function () {
    expect(RoleResource::getModel())->toBe(Role::class);

    // RolePolicy::view() type-hints core's Role, so a Spatie instance would
    // TypeError even once a policy did resolve.
    expect(Gate::getPolicyFor(RoleResource::getModel()))->not->toBeNull();
});

it('denies a panel user holding no role permissions', function () {
    $this->actingAs(roleResourceUser(['view crm leads']));

    expect(RoleResource::canViewAny())->toBeFalse()
        ->and(RoleResource::canCreate())->toBeFalse();
});

it('allows a user holding view crm roles', function () {
    $this->actingAs(roleResourceUser(['view crm roles']));

    expect(RoleResource::canViewAny())->toBeTrue();
});

it('lists CRM roles only, leaving the host application\'s own roles alone', function () {
    $this->actingAs(roleResourceUser(['view crm roles']));

    $hostRole = SpatieRole::findOrCreate('Warehouse Staff');

    livewire(ListRoles::class)
        ->assertCanSeeTableRecords(Role::crm()->get())
        ->assertCanNotSeeTableRecords([Role::find($hostRole->id)]);
});

it('offers CRM permissions only in the permissions checkbox list', function () {
    $this->actingAs(roleResourceUser(['view crm roles', 'edit crm roles']));

    $hostPermission = SpatiePermission::findOrCreate('deploy to production');

    $components = RoleResource::form(Schema::make(
        livewire(ListRoles::class)->instance()
    ))->getFlatComponents(withHidden: true);

    $checkboxList = collect($components)
        ->first(fn ($c) => $c instanceof CheckboxList);

    expect($checkboxList->getOptions())->not->toContain('deploy to production')
        ->and($checkboxList->getOptions())->toContain('view crm leads')
        ->and($hostPermission->crm_permission)->toBeFalsy();
});

it('keeps the Owner and Admin edit/delete guards', function () {
    $this->actingAs(roleResourceUser(['view crm roles', 'edit crm roles', 'delete crm roles']));

    $owner = Role::findByName('Owner');
    $manager = Role::findByName('Manager');

    expect(RoleResource::canEdit($owner))->toBeFalse()
        ->and(RoleResource::canDelete($owner))->toBeFalse()
        ->and(RoleResource::canEdit($manager))->toBeTrue();
});
