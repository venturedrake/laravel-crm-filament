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
                Permission::findOrCreate("{$action} crm {$entity}");
            }
        }

        // Chat / chat-widget perms used by ChatConversationPolicy +
        // ChatWidgetPolicy.
        foreach (['view crm chat', 'reply crm chat', 'delete crm chat', 'manage crm chat widgets'] as $perm) {
            Permission::findOrCreate($perm);
        }

        // Settings perms used by SettingPolicy / RolePolicy /
        // PermissionPolicy / UserPolicy.
        foreach (['view crm settings', 'edit crm settings', 'create crm users', 'view crm users', 'edit crm users', 'delete crm users', 'manage crm feature statuses'] as $perm) {
            Permission::findOrCreate($perm);
        }

        // Updates permission used by the Updates page. Core seeds it as a
        // standalone permission (no create/edit/delete siblings), so it is not
        // expressible through the ENTITIES matrix above — see
        // vendor/venturedrake/laravel-crm/database/seeders/LaravelCrmTablesSeeder.php.
        Permission::findOrCreate('view crm updates');

        $owner = Role::findOrCreate('Owner')->givePermissionTo(Permission::all());
        $admin = Role::findOrCreate('Admin')->givePermissionTo(Permission::all());

        $managerAndEmployeePerms = Permission::query()
            ->whereNotIn('name', ['create crm users', 'edit crm users', 'delete crm users'])
            ->get();

        Role::findOrCreate('Manager')->givePermissionTo($managerAndEmployeePerms);
        Role::findOrCreate('Employee')->givePermissionTo($managerAndEmployeePerms);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
