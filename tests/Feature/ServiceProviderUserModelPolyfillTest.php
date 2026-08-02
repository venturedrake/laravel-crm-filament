<?php

use Illuminate\Support\Facades\Schema;
use VentureDrake\LaravelCrm\Models\Team;
use VentureDrake\LaravelCrmFilament\LaravelCrmFilamentServiceProvider;

/**
 * The `ownerUser` polyfill used to be `belongsTo(App\User::class, 'user_owner_id')`,
 * which is wrong twice over: `App\User` is not the User class most hosts use
 * (Laravel 8+ scaffolds `App\Models\User`), and core CRM's `crm_teams` table has
 * no `user_owner_id` column at all.
 *
 * `tests/Pest.php` aliases the test stub onto `App\User`, so `class_exists()` is
 * useless as a regression guard here — these assertions target the provider
 * source and the resulting relation configuration instead.
 */
function serviceProviderSource(): string
{
    return (string) file_get_contents(
        (new ReflectionClass(LaravelCrmFilamentServiceProvider::class))->getFileName(),
    );
}

it('no longer references App\\User anywhere in the service provider source', function () {
    $source = serviceProviderSource();

    expect($source)->not->toContain('use App\\User;');
    expect($source)->not->toContain('App\\User::class');
    expect($source)->not->toMatch('/\buse App\\\\/');
});

it('resolves the configured user model inside the ownerUser closure', function () {
    $source = serviceProviderSource();

    expect($source)->toContain("belongsTo(config('auth.providers.users.model'), 'user_owner_id')");
});

it('guards the ownerUser polyfill on the user_owner_id column existing', function () {
    $source = serviceProviderSource();

    expect($source)->toContain('if (static::teamsTableHasOwnerColumn()) {');
    expect($source)->toContain("Schema::hasColumn(\$table, 'user_owner_id')");
});

it('detects the owner column off the Team model\'s own table name', function () {
    // Core CRM hardcodes `crm_teams` on the Team model, so the guard must not
    // derive the table from `laravel-crm.db_table_prefix`.
    expect((new Team)->getTable())->toBe('crm_teams');

    $method = new ReflectionMethod(LaravelCrmFilamentServiceProvider::class, 'teamsTableHasOwnerColumn');
    $method->setAccessible(true);

    expect($method->invoke(null))->toBe(
        Schema::hasColumn((new Team)->getTable(), 'user_owner_id'),
    );
});

it('reports false once the owner column is dropped', function () {
    // SQLite has transactional DDL, so LazilyRefreshDatabase rolls this back.
    Schema::table((new Team)->getTable(), function ($table) {
        $table->dropColumn('user_owner_id');
    });

    $method = new ReflectionMethod(LaravelCrmFilamentServiceProvider::class, 'teamsTableHasOwnerColumn');
    $method->setAccessible(true);

    expect($method->invoke(null))->toBeFalse();
});

it('reports false rather than throwing when the teams table is missing entirely', function () {
    Schema::drop((new Team)->getTable());

    $method = new ReflectionMethod(LaravelCrmFilamentServiceProvider::class, 'teamsTableHasOwnerColumn');
    $method->setAccessible(true);

    expect($method->invoke(null))->toBeFalse();
});

it('still reads ownerUser as null on a team, so the parity column renders its placeholder', function () {
    // The polyfill is not registered in the testbench — `packageBooted()` runs
    // before the migrations do, so the column check sees no table yet. Reading
    // an unregistered relation is still a plain null, which is exactly what the
    // `Unallocated` placeholder needs.
    $team = Team::create([
        'user_id' => 1,
        'name' => 'Placeholder Team',
    ]);

    expect($team->fresh()->ownerUser)->toBeNull();
});
