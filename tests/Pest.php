<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;
use VentureDrake\LaravelCrmFilament\Tests\TestCase;

// Core CRM policies declare `App\User` as a typehint. The host app
// usually has either App\User or App\Models\User; in the testbench we
// have neither, so alias our test stub into both so type checks pass.
if (! class_exists('App\\Models\\User')) {
    class_alias(User::class, 'App\\Models\\User');
}
if (! class_exists('App\\User')) {
    class_alias(User::class, 'App\\User');
}

uses(TestCase::class)->in(__DIR__);

/**
 * Act as a CRM admin.
 *
 * The kanban boards' `move*()` / `mark*()` / `convertToDeal()` methods are
 * public Livewire entry points reachable from the browser, so they authorize
 * per record (see AuthorizesKanbanBoard). Tests that exercise the behaviour of
 * those methods — as opposed to the guard itself — need a caller who is allowed
 * to make the write; before the guard existed they ran as a guest and passed.
 */
function actingAsCrmAdmin(): User
{
    RoleSeeder::seed();

    $user = User::create([
        'name' => 'CRM Admin',
        'email' => 'crm-admin-' . Str::random(10) . '@example.com',
        'password' => bcrypt('secret'),
    ]);

    $user->assignRole('Admin');

    test()->actingAs($user->fresh());

    return $user->fresh();
}
