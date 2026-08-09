<?php

use Spatie\Permission\Models\Permission;
use VentureDrake\LaravelCrmFilament\Concerns\AuthorizesCrmSettingsPage;
use VentureDrake\LaravelCrmFilament\Support\TenancyGuard;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;

/**
 * Mechanical: every permission a page gates on must actually exist after the
 * CRM permissions are seeded.
 *
 * ChecksCrmPermissions fails *open* on an unknown permission, so a typo in a
 * `$crmPermission` does not 403 — it silently opens the page to everybody, and
 * nothing anywhere says so. This is the only thing that catches that.
 */
beforeEach(function () {
    RoleSeeder::seed();
});

it('seeds every permission the settings pages gate on', function () {
    $seeded = Permission::query()->pluck('name')->all();

    $missing = [];

    foreach (crmPermissionPages() as $page => $permission) {
        if (! in_array($permission, $seeded, true)) {
            $missing[$page] = $permission;
        }
    }

    expect($missing)->toBe([]);
});

it('declares a permission on every page that uses the settings-authorization concern', function () {
    $undeclared = [];

    foreach (crmAuthorizedPageClasses() as $page) {
        $reflection = new ReflectionClass($page);

        if (! $reflection->hasProperty('crmPermission')) {
            $undeclared[] = $page;
        }
    }

    // The trait deliberately does not declare $crmPermission itself — a trait
    // property and a using-class property with different defaults is a fatal
    // composition error in PHP — so each page owns its declaration, and a page
    // that forgets one would fail with an uninitialised static.
    expect($undeclared)->toBe([]);
});

it('warns rather than throwing when multi-tenancy is on', function () {
    // Throwing in a provider would break config:cache, migrate and queue:work,
    // and would brick a host that flipped the flag after installing.
    config()->set('laravel-crm.teams', true);
    TenancyGuard::forgetWarning();

    expect(TenancyGuard::isEnabled())->toBeTrue()
        ->and(TenancyGuard::shouldWarn())->toBeTrue()
        ->and(TenancyGuard::shouldWarn(acknowledged: true))->toBeFalse()
        ->and(TenancyGuard::message())->toContain('not tenant-aware');

    // Memoised, so a provider that boots on every request logs once.
    TenancyGuard::warnOnce();
    TenancyGuard::warnOnce();

    config()->set('laravel-crm.teams', false);
    TenancyGuard::forgetWarning();

    expect(TenancyGuard::shouldWarn())->toBeFalse();
});

/**
 * @return array<class-string, string>
 */
function crmPermissionPages(): array
{
    $map = [];

    foreach (crmAuthorizedPageClasses() as $page) {
        $reflection = new ReflectionClass($page);

        if (! $reflection->hasProperty('crmPermission')) {
            continue;
        }

        $property = $reflection->getProperty('crmPermission');
        $property->setAccessible(true);

        $map[$page] = $property->getValue();
    }

    return $map;
}

/**
 * Every class in src/ that composes AuthorizesCrmSettingsPage.
 *
 * @return array<int, class-string>
 */
function crmAuthorizedPageClasses(): array
{
    $classes = [];
    $root = dirname(__DIR__, 2) . '/src';

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (! str_contains($source, 'use AuthorizesCrmSettingsPage;')) {
            continue;
        }

        if (! preg_match('/namespace\s+([^;]+);/', $source, $ns)) {
            continue;
        }

        $class = trim($ns[1]) . '\\' . $file->getBasename('.php');

        if (class_exists($class) && in_array(AuthorizesCrmSettingsPage::class, class_uses_recursive($class), true)) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}
