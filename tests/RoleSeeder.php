<?php

namespace VentureDrake\LaravelCrmFilament\Tests;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds Spatie roles + permissions used by the core CRM policies.
 * Mirrors the role / permission matrix from
 * core's LaravelCrmTablesSeeder so policy tests can assign a
 * realistic role to a User and assert the resulting authorization
 * matrix on every Filament Resource.
 */
class RoleSeeder
{
    /** @var list<string> */
    public const ENTITIES = [
        'leads',
        'deals',
        'quotes',
        'orders',
        'invoices',
        'deliveries',
        'purchase orders',
        'people',
        'organizations',
        'contacts',
        'activities',
        'tasks',
        'notes',
        'calls',
        'meetings',
        'lunches',
        'files',
        'pipelines',
        'email-campaigns',
        'email-templates',
        'sms-campaigns',
        'sms-templates',
        'customers',
        'products',
        'product attributes',
        'teams',
        'features',
        'monitors',
        'tax rates',
        'roles',
    ];

    /** @var list<string> */
    public const ROLES = ['Owner', 'Admin', 'Manager', 'Employee'];

    public static function seed(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::ENTITIES as $entity) {
            foreach (['create', 'view', 'edit', 'delete'] as $action) {
                self::permission("{$action} crm {$entity}");
            }
        }

        // Chat / chat-widget perms used by ChatConversationPolicy +
        // ChatWidgetPolicy.
        foreach (['view crm chat', 'reply crm chat', 'delete crm chat', 'manage crm chat widgets'] as $perm) {
            self::permission($perm);
        }

        // Settings perms used by SettingPolicy / RolePolicy /
        // PermissionPolicy / UserPolicy.
        foreach (['view crm settings', 'edit crm settings', 'create crm users', 'view crm users', 'edit crm users', 'delete crm users', 'manage crm feature statuses'] as $perm) {
            self::permission($perm);
        }

        // Updates permission used by the Updates page. Core seeds it as a
        // standalone permission (no create/edit/delete siblings), so it is not
        // expressible through the ENTITIES matrix above — see
        // vendor/venturedrake/laravel-crm/database/seeders/LaravelCrmTablesSeeder.php.
        self::permission('view crm updates');

        self::role('Owner')->givePermissionTo(Permission::all());
        self::role('Admin')->givePermissionTo(Permission::all());

        $managerAndEmployeePerms = Permission::query()
            ->whereNotIn('name', ['create crm users', 'edit crm users', 'delete crm users'])
            ->get();

        self::role('Manager')->givePermissionTo($managerAndEmployeePerms);
        self::role('Employee')->givePermissionTo($managerAndEmployeePerms);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Seed a permission with crm_permission = 1.
     *
     * Core's own seeder sets the flag; without it Permission::crm() — which
     * RoleResource now scopes its checkbox list with — returns nothing.
     */
    protected static function permission(string $name): Permission
    {
        $permission = Permission::findOrCreate($name);

        if (! $permission->crm_permission) {
            $permission->forceFill(['crm_permission' => 1])->save();
        }

        return $permission;
    }

    /**
     * Seed a role with crm_role = 1.
     *
     * Role::assignableBy() is crm() plus an Owner filter, so a role seeded
     * without the flag is invisible to every dropdown, every validation rule
     * and every assignment site — and an assertion against that empty set
     * passes without proving anything.
     */
    protected static function role(string $name): Role
    {
        $role = Role::findOrCreate($name);

        if (! $role->crm_role) {
            $role->forceFill(['crm_role' => 1])->save();
        }

        return $role;
    }
}
