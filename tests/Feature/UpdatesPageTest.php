<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Pages\Updates;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * The Updates page with the network call stubbed, so the outcome of the check
 * can be varied without reaching the version API.
 */
class StubbedCheckUpdates extends Updates
{
    public static bool $succeeds = true;

    protected function fetchLatestVersion(): bool
    {
        return static::$succeeds;
    }
}

/**
 * The Updates page: `--force`, and the version_latest gap.
 */
beforeEach(function () {
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'Updates Tester',
        'email' => 'updates-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->givePermissionTo('view crm updates');
    $this->actingAs($this->user->fresh());
});

it('passes --force when queueing the update command', function () {
    $source = (string) file_get_contents((new ReflectionClass(Updates::class))->getFileName());

    expect($source)->toContain("Artisan::queue('laravelcrm:update', ['--force' => true])")
        // Artisan::queue() discards the exit code, so the notification must not
        // claim the update succeeded — only that it was handed to the queue.
        ->toContain('update_queued_body');
});

it('splits check-for-updates from run-update', function () {
    $instance = livewire(Updates::class)->instance();

    $method = new ReflectionMethod(Updates::class, 'getHeaderActions');
    $method->setAccessible(true);

    $names = array_map(fn ($action) => $action->getName(), $method->invoke($instance));

    // "Check for updates" used to run the update. They are different things:
    // one asks the version API, the other migrates your database.
    expect($names)->toBe(['checkForUpdates', 'runUpdate']);
});

it('requires confirmation before running the update', function () {
    $instance = livewire(Updates::class)->instance();

    expect($instance->getAction('runUpdate')->isConfirmationRequired())->toBeTrue();
});

it('populates version_latest itself rather than waiting for core middleware', function () {
    // version_latest is written only by core's Http\Middleware\Settings and
    // UpdateController, both inside core's web group, which a panel-only host
    // never registers — so UPDATE_AVAILABLE could never fire and this page said
    // "no version information" forever.
    $source = (string) file_get_contents((new ReflectionClass(Updates::class))->getFileName());

    expect($source)->toContain("'version_latest'")
        ->toContain("'install_id'")
        ->toContain(Updates::VERSION_API_URL);

    // Emphatically NOT by registering core's Settings middleware on the panel:
    // that fires ~15 updateOrCreate writes on every request plus a blocking
    // Guzzle call every three days, on a live request.
    $plugin = (string) file_get_contents(dirname(__DIR__, 2) . '/src/LaravelCrmPlugin.php');
    $provider = (string) file_get_contents(dirname(__DIR__, 2) . '/src/LaravelCrmFilamentServiceProvider.php');

    expect($plugin . $provider)->not->toContain('LaravelCrm\\Http\\Middleware\\Settings');
});

it('reads version_latest into the page state', function () {
    Setting::updateOrCreate(['name' => 'version_latest'], ['value' => '99.0.0']);
    app('laravel-crm.settings')->forgetCache();

    $instance = livewire(Updates::class)->instance();

    expect($instance->latestVersion)->toBe('99.0.0')
        ->and($instance->isUpToDate)->toBeFalse();
});

it('reports up to date when nothing newer is published', function () {
    Setting::updateOrCreate(['name' => 'version_latest'], ['value' => '0.0.1']);
    app('laravel-crm.settings')->forgetCache();

    expect(livewire(Updates::class)->instance()->isUpToDate)->toBeTrue();
});

it('answers the DB_UPDATE_REQUIRED question even with update notifications off', function () {
    // check(), not alerts(): alerts() returns nothing at all when
    // update_notifications is off, and this page is where an operator has
    // deliberately come to ask.
    config()->set('laravel-crm.update_notifications', false);

    $source = (string) file_get_contents((new ReflectionClass(Updates::class))->getFileName());

    expect($source)->toContain("app('laravel-crm.system-check')->check()")
        ->not->toContain("app('laravel-crm.system-check')->alerts()");

    expect(livewire(Updates::class)->instance()->systemCheckAlerts)->toBeArray();
});

it('no longer renders version_latest_notes unescaped', function () {
    // Nothing in core writes that setting, and its only conceivable writer is
    // a remote HTTP response — a raw HTML sink is not worth keeping for a
    // feature that never shipped.
    $blade = (string) file_get_contents(
        dirname(__DIR__, 2) . '/resources/views/clusters/settings/pages/updates.blade.php'
    );

    expect($blade)->not->toContain('$this->releaseNotes')
        ->and($blade)->not->toContain('{!!');
});

it('shows the two literal update commands and the upgrade guide link', function () {
    $blade = (string) file_get_contents(
        dirname(__DIR__, 2) . '/resources/views/clusters/settings/pages/updates.blade.php'
    );

    expect($blade)->toContain('composer update venturedrake/laravel-crm venturedrake/laravel-crm-filament')
        ->toContain('php artisan laravelcrm:update')
        ->toContain("config('laravel-crm.upgrade_guide_url')");
});

/**
 * A failed check must say so, even when a previous check left a value behind.
 *
 * Success used to be inferred from `latestVersion === null`. Since a stale
 * `version_latest` survives a failed call, the page cheerfully announced
 * "Version X is available" for a version this run never confirmed — exactly
 * when the operator most needs to hear that the check is broken. The outcome of
 * the call is now returned and used.
 */
it('warns when the version check fails, even with a stale version cached', function () {
    Setting::updateOrCreate(['name' => 'version_latest'], ['value' => '99.99.99']);
    app('laravel-crm.settings')->forgetCache();

    StubbedCheckUpdates::$succeeds = false;

    livewire(StubbedCheckUpdates::class)
        ->callAction('checkForUpdates')
        ->assertNotified(__('laravel-crm-filament::labels.notifications.update_check_failed'));

    // The stale value is left alone rather than being presented as fresh.
    expect(Setting::where('name', 'version_latest')->value('value'))->toBe('99.99.99');
});

it('reports the available version when the check actually succeeds', function () {
    Setting::updateOrCreate(['name' => 'version_latest'], ['value' => '99.99.99']);
    app('laravel-crm.settings')->forgetCache();

    StubbedCheckUpdates::$succeeds = true;

    livewire(StubbedCheckUpdates::class)
        ->callAction('checkForUpdates')
        ->assertNotified(__('laravel-crm-filament::labels.notifications.update_check_available', [
            'version' => '99.99.99',
        ]));
});

it('bounds the version API call so a hung endpoint cannot hang the page', function () {
    // Guzzle defaults both to 0 — "wait forever" — on a call made from a live
    // admin request.
    expect(Updates::VERSION_API_CONNECT_TIMEOUT)->toBeGreaterThan(0)
        ->and(Updates::VERSION_API_TIMEOUT)->toBeGreaterThan(0);

    $source = (string) file_get_contents((new ReflectionClass(Updates::class))->getFileName());

    expect($source)->toContain("'connect_timeout' => self::VERSION_API_CONNECT_TIMEOUT")
        ->toContain("'timeout' => self::VERSION_API_TIMEOUT");
});

it('denies both actions to a user without view crm updates', function () {
    $stranger = User::create([
        'name' => 'Stranger',
        'email' => 'stranger-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    // The permission exists (RoleSeeder seeded it) but this user lacks it, so
    // ChecksCrmPermissions returns false rather than failing open.
    $this->actingAs($stranger->fresh());

    expect(Updates::canAccess())->toBeFalse();
});
