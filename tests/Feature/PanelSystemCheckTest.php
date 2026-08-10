<?php

use Illuminate\Support\Facades\File;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Support\PanelSystemCheck;

/**
 * PanelSystemCheck: "has laravelcrm:filament-update been run against the
 * installed panel code?"
 *
 * Two independent signals, because each has a blind spot on its own — the
 * db_version marker is absent on installs that predate it, and pending
 * migrations are invisible until the migration repository exists.
 */
function panelCheck(): PanelSystemCheck
{
    return app('laravel-crm-filament.system-check');
}

/**
 * Write a migration file into the host's database/migrations, as a publish
 * would, and return its absolute path.
 */
function panelCheckWriteMigration(string $name): string
{
    $path = database_path('migrations/' . $name . '.php');

    File::ensureDirectoryExists(dirname($path));
    File::put($path, "<?php\n\nreturn new class extends Illuminate\\Database\\Migrations\\Migration {\n    public function up(): void {}\n};\n");

    return $path;
}

beforeEach(function () {
    config()->set('laravel-crm.update_notifications', true);

    // Touch the database before anything else so the lazy refresh — and the
    // console-kernel bootstrap it drags in — happens outside the assertions.
    Setting::query()->count();

    $this->writtenMigrations = [];

    panelCheck()->forgetCache();
});

afterEach(function () {
    foreach ($this->writtenMigrations as $path) {
        File::delete($path);
    }

    panelCheck()->forgetCache();
});

it('reports behind when the marker has never been written', function () {
    // A missing marker is not "up to date": either the host predates the marker
    // or the database was never stamped, and both want the same action.
    expect(panelCheck()->dbVersionBehind())->toBeTrue();

    $alerts = panelCheck()->check();

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]['type'])->toBe(PanelSystemCheck::PANEL_DB_UPDATE_REQUIRED)
        ->and($alerts[0]['version_behind'])->toBeTrue();
});

it('reports behind when the marker is older than the installed panel code', function () {
    config()->set('laravel-crm-filament.version', '2.0.0');
    Setting::create(['name' => PanelSystemCheck::DB_VERSION_SETTING, 'value' => '1.9.0', 'global' => 1]);

    expect(panelCheck()->dbVersionBehind())->toBeTrue();
});

it('compares versions numerically, not lexicographically', function () {
    // '1.2.0' < '1.10.0' numerically but not as strings, which is how the old
    // string compare in core silently stopped announcing updates once the minor
    // hit double digits.
    config()->set('laravel-crm-filament.version', '1.10.0');
    Setting::create(['name' => PanelSystemCheck::DB_VERSION_SETTING, 'value' => '1.2.0', 'global' => 1]);

    expect(panelCheck()->dbVersionBehind())->toBeTrue();
});

it('reports up to date when the marker matches the installed panel code', function () {
    config()->set('laravel-crm-filament.version', '1.1.0');
    Setting::create(['name' => PanelSystemCheck::DB_VERSION_SETTING, 'value' => '1.1.0', 'global' => 1]);

    expect(panelCheck()->dbVersionBehind())->toBeFalse()
        ->and(panelCheck()->check())->toBe([]);
});

it('reports up to date when the marker is ahead of the code', function () {
    // A host rolled back to an older panel release. Nagging it to run an update
    // that would do nothing helps nobody.
    config()->set('laravel-crm-filament.version', '1.1.0');
    Setting::create(['name' => PanelSystemCheck::DB_VERSION_SETTING, 'value' => '2.0.0', 'global' => 1]);

    expect(panelCheck()->dbVersionBehind())->toBeFalse();
});

it('treats any one behind duplicate row as behind', function () {
    // Per-team duplicates written by an older install must not be able to mask
    // an outstanding update.
    config()->set('laravel-crm-filament.version', '1.1.0');
    Setting::create(['name' => PanelSystemCheck::DB_VERSION_SETTING, 'value' => '1.1.0', 'global' => 1]);
    Setting::create(['name' => PanelSystemCheck::DB_VERSION_SETTING, 'value' => '1.0.0', 'global' => 1]);

    expect(panelCheck()->dbVersionBehind())->toBeTrue();
});

it('says nothing when there is no code version to compare against', function () {
    config()->set('laravel-crm-filament.version', null);

    expect(panelCheck()->dbVersionBehind())->toBeFalse();
});

it('detects a published panel migration that has not been run', function () {
    $this->writtenMigrations[] = panelCheckWriteMigration('2099_01_01_000000_create_laravel_crm_invoice_payments_table');

    expect(panelCheck()->hasPendingMigrations())->toBeTrue();
});

it('ignores another package\'s unrun migration', function () {
    // The host's migrations and every other package's live in the same
    // directory. Counting those would raise a CRM banner telling the operator
    // to run a CRM command over somebody else's pending migration.
    $this->writtenMigrations[] = panelCheckWriteMigration('2099_01_01_000000_create_someone_elses_table');

    expect(panelCheck()->hasPendingMigrations())->toBeFalse();
});

it('never widens to every migrator path or the whole migrations directory', function () {
    $source = (string) file_get_contents((new ReflectionClass(PanelSystemCheck::class))->getFileName());

    expect($source)->not->toContain('$migrator->paths()');

    // database_path('migrations') is read, but only ever filtered through the
    // published stub names.
    expect($source)->toContain('publishedStubNames()');
});

it('produces a signature that changes with the alerts and is null when there are none', function () {
    expect(panelCheck()->signature())->not->toBeNull();

    config()->set('laravel-crm-filament.version', '1.1.0');
    Setting::create(['name' => PanelSystemCheck::DB_VERSION_SETTING, 'value' => '1.1.0', 'global' => 1]);
    panelCheck()->forgetCache();

    expect(panelCheck()->signature())->toBeNull();
});

it('returns no alerts at all when update notifications are switched off', function () {
    config()->set('laravel-crm.update_notifications', false);

    // check() still answers — the Updates page is where an operator has
    // deliberately come to ask — but alerts(), which the banner renders from,
    // stays silent.
    expect(panelCheck()->alerts())->toBe([])
        ->and(panelCheck()->check())->not->toBe([]);
});

it('reads the marker install-wide, with the team scope dropped', function () {
    // The marker is written from a console command, which has no authenticated
    // user and therefore no team, so a team-scoped read cannot see the row it
    // just wrote.
    $source = (string) file_get_contents((new ReflectionClass(PanelSystemCheck::class))->getFileName());

    expect($source)->toContain('withoutGlobalScope(BelongsToTeamsScope::class)');
});
