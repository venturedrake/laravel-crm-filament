<?php

use Filament\Panel;
use Filament\PanelProvider;
use Filament\PanelRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrm\Scopes\BelongsToTeamsScope;
use VentureDrake\LaravelCrm\Services\SettingService;
use VentureDrake\LaravelCrmFilament\Console\InstallCommand;
use VentureDrake\LaravelCrmFilament\LaravelCrmFilamentServiceProvider;
use VentureDrake\LaravelCrmFilament\Support\PanelSystemCheck;

/**
 * Stand-ins for the base package's `laravelcrm:install`, used to prove the
 * plugin installer forwards `--modules` / `--no-interaction` when the base
 * command supports them and warns-and-skips when it does not (laravel-crm
 * added `--modules` in 2.3.0).
 */
class FakeLaravelCrmInstallWithModules extends Command
{
    public static ?string $modules = null;

    public static ?bool $interactive = null;

    public static bool $ran = false;

    protected $signature = 'laravelcrm:install {--modules=}';

    protected $description = 'Fake base installer that accepts --modules.';

    public function handle(): int
    {
        self::$ran = true;
        self::$modules = $this->option('modules');
        self::$interactive = $this->input->isInteractive();

        return self::SUCCESS;
    }
}

class FakeLaravelCrmInstallWithoutModules extends Command
{
    public static bool $ran = false;

    protected $signature = 'laravelcrm:install';

    protected $description = 'Fake base installer from before --modules existed.';

    public function handle(): int
    {
        self::$ran = true;

        return self::SUCCESS;
    }
}

/**
 * A settings service that cannot write — the shape of a host run with
 * `--skip-crm-install` against a CRM whose tables were never created.
 */
class CrmInstallThrowingSettingService extends SettingService
{
    public function setInstallWide(string $name, $value)
    {
        throw new RuntimeException('no settings table');
    }
}

/**
 * @return array<int, string> Absolute publish targets for the plugin migrations tag.
 */
function crmInstallMigrationPublishTargets(): array
{
    return array_values(ServiceProvider::pathsToPublish(
        LaravelCrmFilamentServiceProvider::class,
        InstallCommand::MIGRATIONS_PUBLISH_TAG,
    ));
}

function crmInstallMakeTempDir(): string
{
    $dir = sys_get_temp_dir() . '/crm-install-test-' . bin2hex(random_bytes(6));
    File::ensureDirectoryExists($dir);

    return $dir;
}

/**
 * Register a fixture PanelProvider so the InstallCommand can find it via
 * `app()->getProviders(PanelProvider::class)` AND via `PanelRegistry::get()`.
 */
function crmInstallRegisterFixtureProvider(string $providerFqcn): PanelProvider
{
    /** @var PanelProvider $provider */
    $provider = new $providerFqcn(app());

    // Push into Application::$serviceProviders so getProviders() picks it up.
    $reflection = new ReflectionClass(app());
    $property = $reflection->getProperty('serviceProviders');
    $property->setAccessible(true);
    $providers = $property->getValue(app());
    $providers[] = $provider;
    $property->setValue(app(), $providers);

    // Register the resulting Panel in the registry so ->get($panelId) resolves it.
    $panel = $provider->panel(Panel::make());
    app(PanelRegistry::class)->register($panel);

    return $provider;
}

beforeEach(function () {
    FakeLaravelCrmInstallWithModules::$modules = null;
    FakeLaravelCrmInstallWithModules::$interactive = null;
    FakeLaravelCrmInstallWithModules::$ran = false;
    FakeLaravelCrmInstallWithoutModules::$ran = false;

    // The installer patches base_path('composer.json') now. Every test that
    // does not redirect the base path resolves that to the testbench skeleton's
    // own composer.json, inside vendor/ — snapshot it here and restore it below
    // so a test run cannot leave the vendor tree patched.
    $this->skeletonComposerPath = base_path('composer.json');
    $this->skeletonComposerContents = File::exists($this->skeletonComposerPath)
        ? File::get($this->skeletonComposerPath)
        : null;
});

afterEach(function () {
    if ($this->skeletonComposerContents !== null) {
        File::put($this->skeletonComposerPath, $this->skeletonComposerContents);
    }

    foreach (glob(sys_get_temp_dir() . '/crm-install-test-*') ?: [] as $dir) {
        File::deleteDirectory($dir);
    }

    // vendor:publish resolves its targets at boot, so the migration lands in
    // the testbench skeleton's database/migrations rather than the per-test
    // temp base path. Remove it so runs stay hermetic.
    foreach (crmInstallMigrationPublishTargets() as $target) {
        File::delete($target);
    }
});

it('publishes CrmPanelProvider and registers it in bootstrap/providers.php on --mode=crm', function () {
    $temp = crmInstallMakeTempDir();

    // Redirect base_path/app_path/env into the fixture dir.
    app()->setBasePath($temp);

    File::ensureDirectoryExists($temp . '/bootstrap');
    File::put(
        $temp . '/bootstrap/providers.php',
        "<?php\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n];\n"
    );

    $this->artisan('laravelcrm:filament-install', ['--mode' => 'crm', '--skip-crm-install' => true])
        ->expectsConfirmation(
            'Add LARAVEL_CRM_USER_INTERFACE=false to your .env now (disables the legacy /crm Livewire UI so the Filament CRM panel can take over /crm)?',
            'no'
        )
        ->assertSuccessful();

    $target = $temp . '/app/Providers/Filament/CrmPanelProvider.php';
    expect(File::exists($target))->toBeTrue();

    $contents = File::get($target);
    expect($contents)
        ->toContain("->id('crm')")
        ->toContain("->path('crm')");

    $providers = File::get($temp . '/bootstrap/providers.php');
    expect($providers)->toContain('App\\Providers\\Filament\\CrmPanelProvider::class');
});

it('injects the LaravelCrmPlugin call and use import on --mode=inject with no conflicts', function () {
    $temp = crmInstallMakeTempDir();
    $suffix = bin2hex(random_bytes(4));
    $panelId = 'target' . $suffix;
    $className = 'FixturePlainPanelProvider' . $suffix;
    $providerFile = $temp . '/' . $className . '.php';

    File::put(
        $providerFile,
        <<<PHP
<?php

namespace VentureDrake\\LaravelCrmFilament\\Tests\\Fixtures\\CrmInstall;

use Filament\\Panel;
use Filament\\PanelProvider;

class {$className} extends PanelProvider
{
    public function panel(Panel \$panel): Panel
    {
        return \$panel
            ->id('{$panelId}')
            ->path('{$panelId}');
    }
}
PHP
    );
    require_once $providerFile;

    $providerFqcn = 'VentureDrake\\LaravelCrmFilament\\Tests\\Fixtures\\CrmInstall\\' . $className;
    crmInstallRegisterFixtureProvider($providerFqcn);

    $this->artisan('laravelcrm:filament-install', [
        '--mode' => 'inject',
        '--panel' => $panelId,
        '--skip-crm-install' => true,
    ])->assertSuccessful();

    $updated = File::get($providerFile);
    expect($updated)
        ->toContain('->plugin(LaravelCrmPlugin::make())')
        ->toContain('use VentureDrake\\LaravelCrmFilament\\LaravelCrmPlugin;');
    expect(substr_count($updated, '->plugin(LaravelCrmPlugin::make())'))->toBe(1);
    expect(substr_count($updated, 'use VentureDrake\\LaravelCrmFilament\\LaravelCrmPlugin;'))->toBe(1);
});

it('reports resource-slug conflicts and leaves the target file untouched on inject with a users collision', function () {
    $temp = crmInstallMakeTempDir();
    $suffix = bin2hex(random_bytes(4));
    $panelId = 'target' . $suffix;
    $providerClassName = 'FixtureConflictPanelProvider' . $suffix;
    $resourceClassName = 'FixtureUsersResource' . $suffix;

    $resourceFile = $temp . '/' . $resourceClassName . '.php';
    File::put(
        $resourceFile,
        <<<PHP
<?php

namespace VentureDrake\\LaravelCrmFilament\\Tests\\Fixtures\\CrmInstall;

use Filament\\Resources\\Resource;

class {$resourceClassName} extends Resource
{
    protected static ?string \$slug = 'users';
}
PHP
    );
    require_once $resourceFile;
    $resourceFqcn = 'VentureDrake\\LaravelCrmFilament\\Tests\\Fixtures\\CrmInstall\\' . $resourceClassName;

    $providerFile = $temp . '/' . $providerClassName . '.php';
    File::put(
        $providerFile,
        <<<PHP
<?php

namespace VentureDrake\\LaravelCrmFilament\\Tests\\Fixtures\\CrmInstall;

use Filament\\Panel;
use Filament\\PanelProvider;

class {$providerClassName} extends PanelProvider
{
    public function panel(Panel \$panel): Panel
    {
        return \$panel
            ->id('{$panelId}')
            ->path('{$panelId}')
            ->resources([\\{$resourceFqcn}::class]);
    }
}
PHP
    );
    require_once $providerFile;
    $providerFqcn = 'VentureDrake\\LaravelCrmFilament\\Tests\\Fixtures\\CrmInstall\\' . $providerClassName;
    crmInstallRegisterFixtureProvider($providerFqcn);

    $before = File::get($providerFile);
    $beforeHash = md5($before);

    $this->artisan('laravelcrm:filament-install', [
        '--mode' => 'inject',
        '--panel' => $panelId,
        '--skip-crm-install' => true,
    ])
        ->expectsOutputToContain('users')
        ->expectsOutputToContain('--mode=crm')
        ->assertFailed();

    expect(md5(File::get($providerFile)))->toBe($beforeHash);
});

it('warns and exits zero without modifying the file when the panel() method cannot be regex-parsed', function () {
    $temp = crmInstallMakeTempDir();
    $suffix = bin2hex(random_bytes(4));
    $panelId = 'quirky' . $suffix;
    $providerClassName = 'FixtureQuirkyPanelProvider' . $suffix;
    $providerFile = $temp . '/' . $providerClassName . '.php';

    // panel() assigns to a local var and `return $configured;` — the regex looks
    // for `return $panel` followed by a chain terminating in `;\s*}`, so this
    // shape does not match and the command should warn + no-op.
    File::put(
        $providerFile,
        <<<PHP
<?php

namespace VentureDrake\\LaravelCrmFilament\\Tests\\Fixtures\\CrmInstall;

use Filament\\Panel;
use Filament\\PanelProvider;

class {$providerClassName} extends PanelProvider
{
    public function panel(Panel \$panel): Panel
    {
        \$configured = \$panel
            ->id('{$panelId}')
            ->path('{$panelId}');

        return \$configured;
    }
}
PHP
    );
    require_once $providerFile;

    $providerFqcn = 'VentureDrake\\LaravelCrmFilament\\Tests\\Fixtures\\CrmInstall\\' . $providerClassName;
    crmInstallRegisterFixtureProvider($providerFqcn);

    $beforeHash = md5(File::get($providerFile));

    $this->artisan('laravelcrm:filament-install', [
        '--mode' => 'inject',
        '--panel' => $panelId,
        '--skip-crm-install' => true,
    ])
        ->expectsOutputToContain('Could not auto-inject')
        ->expectsOutputToContain('LaravelCrmPlugin::make()')
        ->assertExitCode(0);

    expect(md5(File::get($providerFile)))->toBe($beforeHash);
});

it('skips the laravel-crm install check when config/laravel-crm.php is already published', function () {
    $temp = crmInstallMakeTempDir();
    app()->setBasePath($temp);

    File::ensureDirectoryExists($temp . '/config');
    File::put($temp . '/config/laravel-crm.php', "<?php\n\nreturn [];\n");

    File::ensureDirectoryExists($temp . '/bootstrap');
    File::put($temp . '/bootstrap/providers.php', "<?php\n\nreturn [\n];\n");

    // No expectsConfirmation for the CRM install prompt — the check should
    // detect the published config and skip straight to the panel install.
    $this->artisan('laravelcrm:filament-install', ['--mode' => 'crm'])
        ->expectsConfirmation(
            'Add LARAVEL_CRM_USER_INTERFACE=false to your .env now (disables the legacy /crm Livewire UI so the Filament CRM panel can take over /crm)?',
            'no'
        )
        ->assertSuccessful();

    expect(File::exists($temp . '/app/Providers/Filament/CrmPanelProvider.php'))->toBeTrue();
});

it('aborts with failure when laravel-crm is not installed and the user declines to install it', function () {
    $temp = crmInstallMakeTempDir();
    app()->setBasePath($temp);

    File::ensureDirectoryExists($temp . '/bootstrap');
    File::put($temp . '/bootstrap/providers.php', "<?php\n\nreturn [\n];\n");

    // No published config → the command should prompt to run laravelcrm:install.
    $this->artisan('laravelcrm:filament-install', ['--mode' => 'crm'])
        ->expectsConfirmation('Run `php artisan laravelcrm:install` now?', 'no')
        ->expectsOutputToContain('Aborting')
        ->assertFailed();

    // Nothing should have been published because the command bailed early.
    expect(File::exists($temp . '/app/Providers/Filament/CrmPanelProvider.php'))->toBeFalse();
});

it('does not prompt or mutate .env on --mode=crm when LARAVEL_CRM_USER_INTERFACE is already false', function () {
    $temp = crmInstallMakeTempDir();
    app()->setBasePath($temp);

    File::ensureDirectoryExists($temp . '/bootstrap');
    File::put($temp . '/bootstrap/providers.php', "<?php\n\nreturn [\n];\n");

    // Simulate LARAVEL_CRM_USER_INTERFACE=false being live in config.
    config(['laravel-crm.user_interface' => false]);

    File::put($temp . '/.env', "APP_NAME=Test\n");
    $envBefore = File::get($temp . '/.env');

    // No expectsConfirmation() — if the command tries to prompt, Testbench
    // fails the test with "unexpected question".
    $this->artisan('laravelcrm:filament-install', ['--mode' => 'crm', '--skip-crm-install' => true])
        ->assertSuccessful();

    expect(File::get($temp . '/.env'))->toBe($envBefore);
});

it('registers the invoice-payments migration under the crm-filament-migrations tag', function () {
    $targets = ServiceProvider::pathsToPublish(
        LaravelCrmFilamentServiceProvider::class,
        InstallCommand::MIGRATIONS_PUBLISH_TAG,
    );

    // Spatie's shortName() strips the `laravel-` prefix, so the tag is
    // `crm-filament-migrations`, not `laravel-crm-filament-migrations`.
    expect($targets)->not->toBeEmpty();

    $sources = array_keys($targets);
    expect(basename($sources[0]))->toBe('create_laravel_crm_invoice_payments_table.php.stub');
    expect(basename($targets[$sources[0]]))->toEndWith('_create_laravel_crm_invoice_payments_table.php');
    expect(File::get($sources[0]))->toContain("'invoice_payments'");
});

it('publishes and runs the plugin migrations on --mode=crm', function () {
    $temp = crmInstallMakeTempDir();
    app()->setBasePath($temp);

    File::ensureDirectoryExists($temp . '/bootstrap');
    File::put($temp . '/bootstrap/providers.php', "<?php\n\nreturn [\n];\n");

    foreach (crmInstallMigrationPublishTargets() as $target) {
        File::delete($target);
    }

    $this->artisan('laravelcrm:filament-install', [
        '--mode' => 'crm',
        '--skip-crm-install' => true,
        '--no-interaction' => true,
    ])
        ->expectsConfirmation(
            'Add LARAVEL_CRM_USER_INTERFACE=false to your .env now (disables the legacy /crm Livewire UI so the Filament CRM panel can take over /crm)?',
            'no'
        )
        ->expectsOutputToContain('Published and ran the CRM Filament migrations.')
        ->assertSuccessful();

    $published = crmInstallMigrationPublishTargets();
    expect($published)->not->toBeEmpty();

    foreach ($published as $target) {
        expect(File::exists($target))->toBeTrue();
        expect(File::get($target))->toContain("'invoice_payments'");
    }
});

it('publishes and runs the plugin migrations on --mode=inject too', function () {
    // `crm_invoice_payments` backs the invoice Mark Paid history in both
    // modes. `->runsMigrations()` cannot cover it: spatie hands
    // `loadMigrationsFrom()` the `.php.stub` path, and Laravel's Migrator
    // only picks up files ending in `.php`.
    $temp = crmInstallMakeTempDir();
    $suffix = bin2hex(random_bytes(4));
    $panelId = 'target' . $suffix;
    $className = 'FixtureMigrationPanelProvider' . $suffix;
    $providerFile = $temp . '/' . $className . '.php';

    File::put(
        $providerFile,
        <<<PHP
<?php

namespace VentureDrake\\LaravelCrmFilament\\Tests\\Fixtures\\CrmInstall;

use Filament\\Panel;
use Filament\\PanelProvider;

class {$className} extends PanelProvider
{
    public function panel(Panel \$panel): Panel
    {
        return \$panel
            ->id('{$panelId}')
            ->path('{$panelId}');
    }
}
PHP
    );
    require_once $providerFile;

    crmInstallRegisterFixtureProvider(
        'VentureDrake\\LaravelCrmFilament\\Tests\\Fixtures\\CrmInstall\\' . $className
    );

    foreach (crmInstallMigrationPublishTargets() as $target) {
        File::delete($target);
    }

    $this->artisan('laravelcrm:filament-install', [
        '--mode' => 'inject',
        '--panel' => $panelId,
        '--skip-crm-install' => true,
    ])
        ->expectsOutputToContain('Published and ran the CRM Filament migrations.')
        ->assertSuccessful();

    $published = crmInstallMigrationPublishTargets();
    expect($published)->not->toBeEmpty();

    foreach ($published as $target) {
        expect(File::exists($target))->toBeTrue();
        expect(File::get($target))->toContain("'invoice_payments'");
    }
});

it('stamps the panel db_version, so a fresh install raises no update alert', function () {
    // The installer has just published and run the panel migrations.
    // PanelSystemCheck counts a missing marker as behind, so leaving it
    // unwritten would greet the operator on their very first page load with
    // "panel database update required" — over migrations that are already
    // applied, and pointing at a command that would re-run core's update and
    // reseed a brand-new install just to clear it.
    $temp = crmInstallMakeTempDir();
    app()->setBasePath($temp);

    File::ensureDirectoryExists($temp . '/bootstrap');
    File::put($temp . '/bootstrap/providers.php', "<?php\n\nreturn [\n];\n");

    Setting::query()->where('name', PanelSystemCheck::DB_VERSION_SETTING)->delete();

    $this->artisan('laravelcrm:filament-install', [
        '--mode' => 'crm',
        '--skip-crm-install' => true,
        '--no-interaction' => true,
    ])
        ->expectsConfirmation(
            'Add LARAVEL_CRM_USER_INTERFACE=false to your .env now (disables the legacy /crm Livewire UI so the Filament CRM panel can take over /crm)?',
            'no'
        )
        ->expectsOutputToContain('Recorded ' . PanelSystemCheck::DB_VERSION_SETTING)
        ->assertSuccessful();

    // Install-wide, exactly as laravelcrm:filament-update writes it: a console
    // command has no authenticated user and so no team, and a team-scoped row
    // is invisible to the web requests that read Settings through
    // BelongsToTeamsScope.
    $row = Setting::query()
        ->withoutGlobalScope(BelongsToTeamsScope::class)
        ->where('name', PanelSystemCheck::DB_VERSION_SETTING)
        ->first();

    expect($row)->not->toBeNull()
        ->and((string) $row->value)->toBe((string) config('laravel-crm-filament.version'))
        ->and((int) $row->global)->toBe(1)
        ->and($row->team_id)->toBeNull();

    app('laravel-crm-filament.system-check')->forgetCache();

    expect(app('laravel-crm-filament.system-check')->check())->toBe([]);
});

it('warns rather than failing the install when the marker cannot be recorded', function () {
    // Half a panel provider over a migrated database is a worse place to leave
    // a host than an unwritten marker, so this one step is not fatal — it
    // reports the one-line remedy instead.
    $temp = crmInstallMakeTempDir();
    app()->setBasePath($temp);

    File::ensureDirectoryExists($temp . '/bootstrap');
    File::put($temp . '/bootstrap/providers.php', "<?php\n\nreturn [\n];\n");

    app()->instance('laravel-crm.settings', new CrmInstallThrowingSettingService);

    $this->artisan('laravelcrm:filament-install', [
        '--mode' => 'crm',
        '--skip-crm-install' => true,
        '--no-interaction' => true,
    ])
        ->expectsConfirmation(
            'Add LARAVEL_CRM_USER_INTERFACE=false to your .env now (disables the legacy /crm Livewire UI so the Filament CRM panel can take over /crm)?',
            'no'
        )
        ->expectsOutputToContain('Could not record the panel database version')
        ->expectsOutputToContain('laravelcrm:filament-update')
        ->assertSuccessful();
});

it('forwards --modules and --no-interaction to laravelcrm:install when the base command supports them', function () {
    $temp = crmInstallMakeTempDir();
    app()->setBasePath($temp);

    File::ensureDirectoryExists($temp . '/bootstrap');
    File::put($temp . '/bootstrap/providers.php', "<?php\n\nreturn [\n];\n");

    Artisan::registerCommand(new FakeLaravelCrmInstallWithModules);

    // No published config/laravel-crm.php in the temp base path → the command
    // runs the base installer (the confirm defaults to yes non-interactively).
    $this->artisan('laravelcrm:filament-install', [
        '--mode' => 'crm',
        '--modules' => 'leads,deals',
        '--no-interaction' => true,
    ])
        ->expectsConfirmation('Run `php artisan laravelcrm:install` now?', 'yes')
        ->expectsConfirmation(
            'Add LARAVEL_CRM_USER_INTERFACE=false to your .env now (disables the legacy /crm Livewire UI so the Filament CRM panel can take over /crm)?',
            'no'
        )
        ->assertSuccessful();

    expect(FakeLaravelCrmInstallWithModules::$ran)->toBeTrue();
    expect(FakeLaravelCrmInstallWithModules::$modules)->toBe('leads,deals');
    expect(FakeLaravelCrmInstallWithModules::$interactive)->toBeFalse();
});

it('warns and skips --modules when the installed base command has no modules option', function () {
    $temp = crmInstallMakeTempDir();
    app()->setBasePath($temp);

    File::ensureDirectoryExists($temp . '/bootstrap');
    File::put($temp . '/bootstrap/providers.php', "<?php\n\nreturn [\n];\n");

    Artisan::registerCommand(new FakeLaravelCrmInstallWithoutModules);

    $this->artisan('laravelcrm:filament-install', [
        '--mode' => 'crm',
        '--modules' => 'leads,deals',
        '--no-interaction' => true,
    ])
        ->expectsConfirmation('Run `php artisan laravelcrm:install` now?', 'yes')
        ->expectsConfirmation(
            'Add LARAVEL_CRM_USER_INTERFACE=false to your .env now (disables the legacy /crm Livewire UI so the Filament CRM panel can take over /crm)?',
            'no'
        )
        ->expectsOutputToContain('does not support `--modules`')
        ->assertSuccessful();

    // The base installer still runs — the unsupported option is simply dropped.
    expect(FakeLaravelCrmInstallWithoutModules::$ran)->toBeTrue();
    expect(File::exists($temp . '/app/Providers/Filament/CrmPanelProvider.php'))->toBeTrue();
});

/**
 * The composer `post-autoload-dump` hook.
 *
 * `laravelcrm:filament-upgrade` clears the cached Filament panel components,
 * which is what makes newly-shipped resources and pages appear after an
 * upgrade. Nobody remembers to run it, so the installer wires it into the hook
 * that already fires on every `composer install`.
 */
function crmInstallTempAppWithComposerJson(string $composerJson): string
{
    $temp = crmInstallMakeTempDir();
    app()->setBasePath($temp);

    File::ensureDirectoryExists($temp . '/bootstrap');
    File::put($temp . '/bootstrap/providers.php', "<?php\n\nreturn [\n];\n");

    // The CRM config being present skips the base-installer prompt, and
    // user_interface being off skips the .env prompt — neither is what these
    // tests are about.
    File::ensureDirectoryExists($temp . '/config');
    File::put($temp . '/config/laravel-crm.php', "<?php\n\nreturn [];\n");
    config(['laravel-crm.user_interface' => false]);

    File::put($temp . '/composer.json', $composerJson);

    return $temp;
}

it('appends the filament-upgrade hook to post-autoload-dump', function () {
    $temp = crmInstallTempAppWithComposerJson(<<<'JSON'
{
    "name": "acme/app",
    "require": {},
    "scripts": {
        "post-autoload-dump": [
            "@php artisan package:discover --ansi"
        ]
    }
}
JSON);

    $this->artisan('laravelcrm:filament-install', ['--mode' => 'crm'])->assertSuccessful();

    $composer = json_decode(File::get($temp . '/composer.json'), true);

    // Appended, never prepended: package:discover has to have run before an
    // artisan command from a package can be resolved at all.
    expect($composer['scripts']['post-autoload-dump'])->toBe([
        '@php artisan package:discover --ansi',
        InstallCommand::COMPOSER_HOOK_ENTRY,
    ]);
});

it('creates the scripts block when the host composer.json has none', function () {
    $temp = crmInstallTempAppWithComposerJson(<<<'JSON'
{
    "name": "acme/app",
    "require": {},
    "config": {}
}
JSON);

    $this->artisan('laravelcrm:filament-install', ['--mode' => 'crm'])->assertSuccessful();

    $raw = File::get($temp . '/composer.json');
    $composer = json_decode($raw, true);

    expect($composer['scripts']['post-autoload-dump'])->toBe([InstallCommand::COMPOSER_HOOK_ENTRY]);

    // Decoded to objects, not associative arrays: `"config": {}` decoded to an
    // array re-encodes as `[]`, and composer rejects the manifest.
    expect($raw)->toContain('"config": {}')
        ->not->toContain('"config": []');
});

it('handles a post-autoload-dump written as a bare string', function () {
    $temp = crmInstallTempAppWithComposerJson(<<<'JSON'
{
    "name": "acme/app",
    "scripts": {
        "post-autoload-dump": "@php artisan package:discover --ansi"
    }
}
JSON);

    $this->artisan('laravelcrm:filament-install', ['--mode' => 'crm'])->assertSuccessful();

    $composer = json_decode(File::get($temp . '/composer.json'), true);

    expect($composer['scripts']['post-autoload-dump'])->toBe([
        '@php artisan package:discover --ansi',
        InstallCommand::COMPOSER_HOOK_ENTRY,
    ]);
});

it('is idempotent — a second install adds no second hook line', function () {
    $temp = crmInstallTempAppWithComposerJson(<<<'JSON'
{
    "name": "acme/app",
    "scripts": {
        "post-autoload-dump": [
            "@php artisan package:discover --ansi"
        ]
    }
}
JSON);

    $this->artisan('laravelcrm:filament-install', ['--mode' => 'crm', '--force' => true])->assertSuccessful();

    $afterFirst = File::get($temp . '/composer.json');

    $this->artisan('laravelcrm:filament-install', ['--mode' => 'crm', '--force' => true])
        ->expectsOutputToContain('already runs laravelcrm:filament-upgrade')
        ->assertSuccessful();

    expect(File::get($temp . '/composer.json'))->toBe($afterFirst);
    expect(substr_count($afterFirst, 'laravelcrm:filament-upgrade'))->toBe(1);
});

it('warns and leaves an unparseable composer.json byte-identical', function () {
    // A composer.json is hand-maintained. Losing one is worse than not
    // patching it, so anything we cannot fully understand is left alone and
    // the line to add is printed instead.
    $broken = "{\n    \"name\": \"acme/app\",\n";

    $temp = crmInstallTempAppWithComposerJson($broken);

    $this->artisan('laravelcrm:filament-install', ['--mode' => 'crm'])
        ->expectsOutputToContain('Could not parse')
        ->expectsOutputToContain(InstallCommand::COMPOSER_HOOK_ENTRY)
        ->assertSuccessful();

    expect(File::get($temp . '/composer.json'))->toBe($broken);
});

it('leaves a composer.json with an unexpected scripts shape alone', function () {
    $original = <<<'JSON'
{
    "name": "acme/app",
    "scripts": "not-an-object"
}
JSON;

    $temp = crmInstallTempAppWithComposerJson($original);

    $this->artisan('laravelcrm:filament-install', ['--mode' => 'crm'])
        ->expectsOutputToContain('Unexpected "scripts" value')
        ->assertSuccessful();

    expect(File::get($temp . '/composer.json'))->toBe($original);
});

it('skips the composer hook entirely under --no-composer-hook', function () {
    $original = <<<'JSON'
{
    "name": "acme/app",
    "scripts": {
        "post-autoload-dump": [
            "@php artisan package:discover --ansi"
        ]
    }
}
JSON;

    $temp = crmInstallTempAppWithComposerJson($original);

    $this->artisan('laravelcrm:filament-install', ['--mode' => 'crm', '--no-composer-hook' => true])
        ->assertSuccessful();

    expect(File::get($temp . '/composer.json'))->toBe($original);
});

it('adds only the database-free half to the composer hook', function () {
    // post-autoload-dump fires on every `composer install`, including on a
    // production box mid-deploy with no TTY and possibly no reachable database.
    // Only laravelcrm:filament-upgrade is safe there; the migrating half stays
    // explicit.
    expect(InstallCommand::COMPOSER_HOOK_ENTRY)
        ->toContain('laravelcrm:filament-upgrade')
        ->not->toContain('laravelcrm:filament-update');
});
