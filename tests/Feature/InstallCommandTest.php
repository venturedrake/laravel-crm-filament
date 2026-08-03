<?php

use Filament\Panel;
use Filament\PanelProvider;
use Filament\PanelRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use VentureDrake\LaravelCrmFilament\Console\InstallCommand;
use VentureDrake\LaravelCrmFilament\LaravelCrmFilamentServiceProvider;

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
});

afterEach(function () {
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
