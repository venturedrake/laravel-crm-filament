<?php

use Illuminate\Console\Command;
use Illuminate\Support\ServiceProvider;
use VentureDrake\LaravelCrmFilament\Console\Concerns\StampsPanelDbVersion;
use VentureDrake\LaravelCrmFilament\LaravelCrmFilamentServiceProvider;
use VentureDrake\LaravelCrmFilament\Support\PanelSystemCheck;

/**
 * A minimal command carrying the concern, so the version lookup the two real
 * commands share can be asked its question directly.
 */
class PanelVersionStamper extends Command
{
    use StampsPanelDbVersion;

    protected $signature = 'test:panel-version-stamper';

    public function readVersion(): string
    {
        return $this->panelCodeVersion();
    }
}

/**
 * The panel's version constant.
 *
 * Everything about the update workflow keys off it: PanelSystemCheck compares
 * it against the `crm_filament_db_version` marker, and
 * `laravelcrm:filament-update` stamps it. A release that bumps the CHANGELOG
 * and forgets the constant silently disables that whole check — the marker
 * stays equal to the code version forever and nothing ever reports being
 * behind. So the two are asserted to agree here rather than left to a release
 * checklist.
 */
function panelPackageRoot(): string
{
    return dirname(__DIR__, 2);
}

/**
 * The topmost released heading in CHANGELOG.md — `[Unreleased]` skipped.
 */
function changelogLatestReleasedVersion(): ?string
{
    $changelog = (string) file_get_contents(panelPackageRoot() . '/CHANGELOG.md');

    preg_match_all('/^##\s*\[([^\]]+)\]/m', $changelog, $matches);

    foreach ($matches[1] as $heading) {
        if (strcasecmp($heading, 'Unreleased') === 0) {
            continue;
        }

        return $heading;
    }

    return null;
}

it('matches the topmost released CHANGELOG heading', function () {
    $released = changelogLatestReleasedVersion();

    expect($released)->not->toBeNull();
    expect(config('laravel-crm-filament.version'))->toBe($released);
});

it('is a valid semver string', function () {
    expect((string) config('laravel-crm-filament.version'))->toMatch('/^\d+\.\d+\.\d+/');
});

it('is merged from a non-publishable config file', function () {
    // Not Package::hasConfigFile(): a host that published this file could pin
    // the version, and a pinned version is a permanently-silent "your database
    // is up to date". Mirrors laravel-crm's own config/package.php.
    expect(file_exists(panelPackageRoot() . '/config/package.php'))->toBeTrue();

    $provider = (string) file_get_contents(panelPackageRoot() . '/src/LaravelCrmFilamentServiceProvider.php');

    expect($provider)->toContain('mergeConfigFrom(')
        ->toContain('config/package.php');

    // The load-bearing assertion: nothing the package publishes writes this
    // file into the host, so there is no copy for a host to edit.
    $publishable = ServiceProvider::pathsToPublish(LaravelCrmFilamentServiceProvider::class);

    foreach (array_keys($publishable) as $source) {
        expect(basename($source))->not->toBe('package.php');
    }

    expect(config('laravel-crm-filament.version'))->not->toBeNull();
});

it('is the value the commands stamp and the check reads', function () {
    expect(PanelSystemCheck::DB_VERSION_SETTING)->toBe('crm_filament_db_version');

    $concern = (string) file_get_contents(panelPackageRoot() . '/src/Console/Concerns/StampsPanelDbVersion.php');

    expect($concern)->toContain("config('laravel-crm-filament.version')")
        ->toContain('PanelSystemCheck::DB_VERSION_SETTING');

    // Shared, not duplicated: an installer that migrated without stamping would
    // raise "panel database update required" over its own fresh migrations.
    foreach (['InstallCommand', 'UpdateCommand'] as $command) {
        expect((string) file_get_contents(panelPackageRoot() . "/src/Console/{$command}.php"))
            ->toContain('use StampsPanelDbVersion;');
    }
});

it('is readable from disk when the host config cache predates this release', function () {
    // mergeConfigFrom() is a no-op whenever the configuration is cached, so on
    // a box holding a config:cache written before this release the key is
    // absent for the whole process — and config:clear does not bring it back,
    // it only deletes the file. Without the fallback, laravelcrm:filament-update
    // would migrate, skip the marker and still report success.
    config()->set('laravel-crm-filament.version', null);

    expect((new PanelVersionStamper)->readVersion())
        ->toBe((string) (require panelPackageRoot() . '/config/package.php')['version']);
});
