<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrm\Scopes\BelongsToTeamsScope;
use VentureDrake\LaravelCrm\Services\SettingService;
use VentureDrake\LaravelCrmFilament\Console\InstallCommand;
use VentureDrake\LaravelCrmFilament\Console\UpdateCommand;
use VentureDrake\LaravelCrmFilament\LaravelCrmFilamentServiceProvider;
use VentureDrake\LaravelCrmFilament\Support\PanelSystemCheck;

/**
 * `laravelcrm:filament-update`: the half that touches the database.
 *
 * It runs core's `laravelcrm:update` first — core's schema underneath the
 * panel's, since this package's table carries foreign keys into core's — then
 * publishes and runs this package's own migrations, then stamps the marker that
 * says this release's database work is done.
 *
 * The stand-ins below exist because the real `laravelcrm:update` cannot be run
 * from a test — it migrates and reseeds — and because both sides of every
 * version probe need covering: `--force` on `laravelcrm:update` and
 * `SettingService::setInstallWide()` arrived in laravel-crm 2.4.0, and passing
 * an option an older base command does not define aborts the whole run rather
 * than degrading.
 */

/**
 * The base package's update command as it exists from laravel-crm 2.4.0.
 */
class FakeLaravelCrmUpdateWithForce extends Command
{
    public static bool $ran = false;

    public static ?bool $force = null;

    public static ?bool $interactive = null;

    public static int $exitCode = 0;

    /**
     * Whether this package's migration had already been published when core's
     * update ran — the observable form of "core first, plugin second".
     */
    public static ?bool $panelMigrationAlreadyPublished = null;

    protected $signature = 'laravelcrm:update {--force}';

    protected $description = 'Fake base update command that accepts --force.';

    public function handle(): int
    {
        self::$ran = true;
        self::$force = (bool) $this->option('force');
        self::$interactive = $this->input->isInteractive();
        self::$panelMigrationAlreadyPublished = filamentUpdatePublishedMigrationExists();

        return self::$exitCode;
    }
}

/**
 * The same command as it exists on laravel-crm 2.0–2.3, with no --force option.
 */
class FakeLaravelCrmUpdateWithoutForce extends Command
{
    public static bool $ran = false;

    protected $signature = 'laravelcrm:update';

    protected $description = 'Fake base update command from before --force existed.';

    public function handle(): int
    {
        self::$ran = true;

        return self::SUCCESS;
    }
}

/**
 * The plugin's update command on a host where venturedrake/laravel-crm is not
 * installed at all. `find()` is overridden rather than the artisan application
 * mutated, because Symfony's Application has no way to remove a command.
 */
class UpdateCommandWithMissingCrmUpdate extends UpdateCommand
{
    protected $signature = 'test:filament-update-missing-crm
                           {--force}
                           {--skip-crm-update}';

    protected function findConsoleCommand(string $command): ?SymfonyCommand
    {
        if ($command === UpdateCommand::CRM_UPDATE_COMMAND) {
            return null;
        }

        return parent::findConsoleCommand($command);
    }
}

/**
 * A SettingService that ships setInstallWide(), as laravel-crm 2.4+ does.
 *
 * Extends the real service rather than standing apart from it: core 2.4's
 * LaravelCrmUpdate type-hints SettingService in its constructor, and the
 * container resolves that through the same `laravel-crm.settings` binding this
 * replaces.
 */
class FakeSettingServiceWithInstallWide extends SettingService
{
    /** @var array<string, string> */
    public static array $installWide = [];

    public static bool $cacheForgotten = false;

    public function setInstallWide(string $name, $value)
    {
        self::$installWide[$name] = (string) $value;

        return null;
    }

    public function get(string $name, $default = null)
    {
        return self::$installWide[$name] ?? $default;
    }

    public function all(): array
    {
        return self::$installWide;
    }

    public function forgetCache(): void
    {
        self::$cacheForgotten = true;
    }
}

/**
 * @return array<int, string> Absolute publish targets for the plugin migrations tag.
 */
function filamentUpdateMigrationTargets(): array
{
    return array_values(ServiceProvider::pathsToPublish(
        LaravelCrmFilamentServiceProvider::class,
        InstallCommand::MIGRATIONS_PUBLISH_TAG,
    ));
}

function filamentUpdatePublishedMigrationExists(): bool
{
    foreach (filamentUpdateMigrationTargets() as $target) {
        if (File::exists($target)) {
            return true;
        }
    }

    return false;
}

/**
 * The marker read the way PanelSystemCheck reads it — install-wide, with the
 * team scope dropped, because a console command writes it with no team.
 */
function filamentUpdateStampedVersion(): ?string
{
    $row = Setting::query()
        ->withoutGlobalScope(BelongsToTeamsScope::class)
        ->where('name', PanelSystemCheck::DB_VERSION_SETTING)
        ->first();

    return $row === null ? null : (string) $row->value;
}

/**
 * Everything already sitting in the skeleton's database/migrations.
 *
 * `vendor:publish` resolves its targets at boot, so publishes land in the
 * testbench skeleton — inside vendor/ — rather than in a per-test temp dir.
 * Snapshotting the directory and removing whatever a test added is the only
 * way this file can run `vendor:publish` and `migrate` without leaving the
 * vendor tree in a state that breaks the next run's `migrate`.
 *
 * @return array<int, string>
 */
function filamentUpdateSkeletonMigrations(): array
{
    return glob(database_path('migrations/*.php')) ?: [];
}

beforeEach(function () {
    $this->skeletonMigrationsBefore = filamentUpdateSkeletonMigrations();

    FakeLaravelCrmUpdateWithForce::$ran = false;
    FakeLaravelCrmUpdateWithForce::$force = null;
    FakeLaravelCrmUpdateWithForce::$interactive = null;
    FakeLaravelCrmUpdateWithForce::$exitCode = 0;
    FakeLaravelCrmUpdateWithForce::$panelMigrationAlreadyPublished = null;
    FakeLaravelCrmUpdateWithoutForce::$ran = false;
    FakeSettingServiceWithInstallWide::$installWide = [];
    FakeSettingServiceWithInstallWide::$cacheForgotten = false;

    // vendor:publish resolves its targets at boot, so the migration lands in
    // the testbench skeleton's database/migrations. Start from a clean slate so
    // the "published yet?" ordering probe means something.
    foreach (filamentUpdateMigrationTargets() as $target) {
        File::delete($target);
    }
});

afterEach(function () {
    foreach (filamentUpdateMigrationTargets() as $target) {
        File::delete($target);
    }

    foreach (array_diff(filamentUpdateSkeletonMigrations(), $this->skeletonMigrationsBefore) as $added) {
        File::delete($added);
    }
});

it('updates the base CRM package before publishing and running the panel migrations', function () {
    Artisan::registerCommand(new FakeLaravelCrmUpdateWithForce);

    $this->artisan('laravelcrm:filament-update', ['--force' => true])
        ->expectsOutputToContain('is now updated')
        ->assertSuccessful();

    expect(FakeLaravelCrmUpdateWithForce::$ran)->toBeTrue();

    // Core first is not a preference: crm_invoice_payments carries foreign keys
    // into core's tables, and every resource the panel boots reads core's
    // schema.
    expect(FakeLaravelCrmUpdateWithForce::$panelMigrationAlreadyPublished)->toBeFalse();
    expect(filamentUpdatePublishedMigrationExists())->toBeTrue();
});

it('forwards --force to laravelcrm:update when the installed base command accepts it', function () {
    Artisan::registerCommand(new FakeLaravelCrmUpdateWithForce);

    $this->artisan('laravelcrm:filament-update', ['--force' => true])->assertSuccessful();

    expect(FakeLaravelCrmUpdateWithForce::$force)->toBeTrue();
});

it('drops --force, with a warning, on a base version that has no such option', function () {
    // laravel-crm 2.0–2.3 has no --force on laravelcrm:update, and passing it
    // aborts with "The --force option does not exist" rather than degrading.
    Artisan::registerCommand(new FakeLaravelCrmUpdateWithoutForce);

    $this->artisan('laravelcrm:filament-update', ['--force' => true])
        ->expectsOutputToContain('does not support `--force`')
        ->assertSuccessful();

    expect(FakeLaravelCrmUpdateWithoutForce::$ran)->toBeTrue();
});

it('forwards --no-interaction so a scripted deploy cannot stall on core\'s prompt', function () {
    // Command::call() builds a fresh, interactive input, so core's production
    // confirmation would otherwise be asked with nobody there to answer.
    Artisan::registerCommand(new FakeLaravelCrmUpdateWithForce);

    $this->artisan('laravelcrm:filament-update', ['--force' => true, '--no-interaction' => true])
        ->assertSuccessful();

    expect(FakeLaravelCrmUpdateWithForce::$interactive)->toBeFalse();
});

it('skips laravelcrm:update under --skip-crm-update but still migrates the panel', function () {
    Artisan::registerCommand(new FakeLaravelCrmUpdateWithForce);

    $this->artisan('laravelcrm:filament-update', ['--force' => true, '--skip-crm-update' => true])
        ->expectsOutputToContain('Skipping laravelcrm:update')
        ->assertSuccessful();

    expect(FakeLaravelCrmUpdateWithForce::$ran)->toBeFalse();
    expect(filamentUpdatePublishedMigrationExists())->toBeTrue();
    expect(filamentUpdateStampedVersion())->toBe((string) config('laravel-crm-filament.version'));
});

it('aborts and leaves the marker unstamped when laravelcrm:update fails', function () {
    Artisan::registerCommand(new FakeLaravelCrmUpdateWithForce);
    FakeLaravelCrmUpdateWithForce::$exitCode = 1;

    // Fatal, and it says so. Core 2.4 fixed the opposite behaviour — catching
    // the failure, warning, and still printing success — which made a broken
    // upgrade indistinguishable from a clean one in a deploy log.
    $this->artisan('laravelcrm:filament-update', ['--force' => true])
        ->expectsOutputToContain('has NOT been updated')
        ->assertFailed();

    // Unstamped, or the panel would stop telling the operator to re-run this.
    expect(filamentUpdateStampedVersion())->toBeNull();
});

it('fails with manual instructions when laravelcrm:update is not registered at all', function () {
    Artisan::registerCommand(new UpdateCommandWithMissingCrmUpdate);

    $this->artisan('test:filament-update-missing-crm', ['--force' => true])
        ->expectsOutputToContain('is not registered')
        ->expectsOutputToContain('--skip-crm-update')
        ->expectsOutputToContain('has NOT been updated')
        ->assertFailed();

    expect(filamentUpdateStampedVersion())->toBeNull();
});

it('stamps the panel db_version install-wide on success', function () {
    Artisan::registerCommand(new FakeLaravelCrmUpdateWithForce);

    $this->artisan('laravelcrm:filament-update', ['--force' => true])->assertSuccessful();

    expect(filamentUpdateStampedVersion())->toBe((string) config('laravel-crm-filament.version'));

    // Install-wide, because a console command has no authenticated user and so
    // no team: a team-scoped row is invisible to the web requests that read
    // Settings through BelongsToTeamsScope, and the panel would keep reporting
    // an update this command has just completed.
    $row = Setting::query()
        ->withoutGlobalScope(BelongsToTeamsScope::class)
        ->where('name', PanelSystemCheck::DB_VERSION_SETTING)
        ->first();

    expect((int) $row->global)->toBe(1)
        ->and($row->team_id)->toBeNull();
});

it('rewrites every duplicate marker row, not just the first', function () {
    // Per-team duplicates left by an older install must not be able to mask an
    // outstanding update: PanelSystemCheck counts any row behind as behind.
    Setting::create(['name' => PanelSystemCheck::DB_VERSION_SETTING, 'value' => '0.0.1', 'global' => 1]);
    Setting::create(['name' => PanelSystemCheck::DB_VERSION_SETTING, 'value' => '0.0.2', 'global' => 1]);

    Artisan::registerCommand(new FakeLaravelCrmUpdateWithForce);

    $this->artisan('laravelcrm:filament-update', ['--force' => true])->assertSuccessful();

    $values = Setting::query()
        ->withoutGlobalScope(BelongsToTeamsScope::class)
        ->where('name', PanelSystemCheck::DB_VERSION_SETTING)
        ->pluck('value')
        ->all();

    expect($values)->toBe([
        (string) config('laravel-crm-filament.version'),
        (string) config('laravel-crm-filament.version'),
    ]);
});

it('uses SettingService::setInstallWide() when the installed base package ships it', function () {
    // Present from laravel-crm 2.4. The direct-write fallback exercised by the
    // tests above is for 2.0–2.3, which is what composer.lock pins here.
    app()->instance('laravel-crm.settings', new FakeSettingServiceWithInstallWide);

    Artisan::registerCommand(new FakeLaravelCrmUpdateWithForce);

    $this->artisan('laravelcrm:filament-update', ['--force' => true])->assertSuccessful();

    expect(FakeSettingServiceWithInstallWide::$installWide)
        ->toBe([PanelSystemCheck::DB_VERSION_SETTING => (string) config('laravel-crm-filament.version')]);

    expect(FakeSettingServiceWithInstallWide::$cacheForgotten)->toBeTrue();

    // Nothing written directly — the service owns the row.
    expect(filamentUpdateStampedVersion())->toBeNull();
});

it('clears the panel system check cache so the banner does not survive the fix', function () {
    // Warm the cache with a "behind" answer. Deliberately before the fake is
    // registered: this touches the database, which bootstraps the console
    // kernel, and the package command registrations that happen there would
    // otherwise land on top of the fake and hand the call to the real
    // laravelcrm:update.
    config()->set('laravel-crm.update_notifications', true);
    expect(app('laravel-crm-filament.system-check')->alerts())->not->toBeEmpty();

    Artisan::registerCommand(new FakeLaravelCrmUpdateWithForce);

    $this->artisan('laravelcrm:filament-update', ['--force' => true])->assertSuccessful();

    expect(app('laravel-crm-filament.system-check')->alerts())->toBeEmpty();
});

it('runs the cache-clearing half first', function () {
    // So the panel's component and config caches are already gone by the time
    // the migrations land. It does not rescue this process's own config — see
    // the test below.
    Artisan::registerCommand(new FakeLaravelCrmUpdateWithForce);

    $this->artisan('laravelcrm:filament-update', ['--force' => true])
        ->expectsOutputToContain('Upgrading the Laravel CRM Filament panel')
        ->assertSuccessful();

    expect(UpdateCommand::UPGRADE_COMMAND)->toBe('laravelcrm:filament-upgrade');
});

it('stamps the real version on a host whose config cache predates this release', function () {
    // The upgrading host, exactly: mergeConfigFrom() is a no-op while the
    // configuration is cached, so `laravel-crm-filament.version` is absent for
    // the whole process — and the filament-upgrade step cannot bring it back,
    // because config:clear only deletes bootstrap/cache/config.php and leaves
    // the loaded repository as it was.
    //
    // Without reading the constant off disk, this run would migrate, skip the
    // marker and still print "is now updated" — and the banner would reappear
    // the moment the operator re-ran config:cache.
    $version = (string) config('laravel-crm-filament.version');

    config()->set('laravel-crm-filament.version', null);

    Artisan::registerCommand(new FakeLaravelCrmUpdateWithForce);

    $this->artisan('laravelcrm:filament-update', ['--force' => true])
        ->doesntExpectOutputToContain('skipping the ' . PanelSystemCheck::DB_VERSION_SETTING)
        ->assertSuccessful();

    expect(filamentUpdateStampedVersion())->toBe($version);
});
