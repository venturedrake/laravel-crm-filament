<?php

namespace VentureDrake\LaravelCrmFilament\Tests;

use Barryvdh\DomPDF\ServiceProvider as DomPdfServiceProvider;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Codeat3\BladeForkAwesome\BladeForkAwesomeServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\LivewireServiceProvider;
use MallardDuck\BladeBoxicons\BladeBoxiconsServiceProvider;
use Mary\MaryServiceProvider;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;
use OwenVoke\BladeFontAwesome\BladeFontAwesomeServiceProvider;
use Spatie\Permission\PermissionServiceProvider;
use VentureDrake\LaravelCrm\LaravelCrmServiceProvider;
use VentureDrake\LaravelCrm\View\Composers\SettingsComposer;
use VentureDrake\LaravelCrmFilament\LaravelCrmFilamentServiceProvider;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

class TestCase extends Orchestra
{
    use LazilyRefreshDatabase;
    use WithWorkbench;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset core SettingsComposer cache + flush cache so test runs don't
        // poison each other.
        if (property_exists(SettingsComposer::class, 'cachedParameters')) {
            SettingsComposer::$cachedParameters = null;
        }
        Cache::flush();

        // Stub the Xero facade accessor so services that call Xero::isConnected()
        // (Product/Quote/Invoice/Order services) don't blow up.
        $this->app->instance('xero', new class
        {
            public function isConnected(): bool
            {
                return false;
            }

            public function __call($name, $args)
            {
                return null;
            }
        });
    }

    protected function getPackageProviders($app)
    {
        $providers = [
            ActionsServiceProvider::class,
            // The plugin renders PDFs directly (CrmPdf, PdfTemplatePreview), so
            // the dompdf.wrapper binding has to exist in the harness too.
            DomPdfServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            LivewireServiceProvider::class,
            NotificationsServiceProvider::class,
            PermissionServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeBoxiconsServiceProvider::class,
            BladeFontAwesomeServiceProvider::class,
            BladeForkAwesomeServiceProvider::class,
            MaryServiceProvider::class,
            LaravelCrmServiceProvider::class,
            LaravelCrmFilamentServiceProvider::class,
            TestPanelProvider::class,
        ];

        sort($providers);

        return $providers;
    }

    public function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('app.cipher', 'AES-256-CBC');

        $app['config']->set('laravel-crm.db_table_prefix', 'crm_');
        $app['config']->set('laravel-crm.teams', false);
        $app['config']->set('laravel-crm.encrypt_db_fields', false);
        $app['config']->set('laravel-crm.route_prefix', 'crm');
        $app['config']->set('laravel-crm.user_interface', true);

        // cknow/laravel-money reads this path when a PDF template formats an
        // amount. Its own provider is not registered in the harness, so the
        // config key is absent and ISOCurrencies throws "Failed to load
        // currency ISO codes." — which surfaces as a broken PDF, not a
        // missing-provider error.
        $app['config']->set(
            'money.isoCurrenciesPath',
            dirname(__DIR__) . '/vendor/moneyphp/money/resources/currency.php',
        );

        // Spatie permissions in testing mode (sqlite) — see
        // create_permission_tables migration.
        $app['config']->set('permission.testing', false);
        $app['config']->set('permission.teams', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        // Bootstraps the core CRM schema (and Spatie permission tables) into
        // the testbench's in-memory SQLite database. The core's full migration
        // set includes raw MySQL ALTER statements that don't run on SQLite,
        // so we use a TestSchema bootstrap migration instead — identical
        // shape to the core package's own test setup.
        $this->loadMigrationsFrom(__DIR__ . '/Database/Migrations');
    }
}
