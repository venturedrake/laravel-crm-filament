<?php

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use VentureDrake\LaravelCrmFilament\Pages\Integrations;
use VentureDrake\LaravelCrmFilament\Support\XeroIntegration;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * Core 2.4.5 moved dcblogdev/laravel-xero from `require` to `suggest`, so on a
 * current core the package is only there if the host asked for it. The panel
 * must render without it, and say what is missing instead of offering a
 * Connect button that bounces to core's own settings screen.
 */
beforeEach(function () {
    RoleSeeder::seed();

    $admin = User::create([
        'name' => 'Xero Admin',
        'email' => 'xero-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $admin->givePermissionTo('view crm settings');
    $admin->givePermissionTo('edit crm settings');

    $this->actingAs($admin->fresh());
});

afterEach(function () {
    XeroIntegration::$installedUsing = null;
});

function xeroConnectedStub(bool $connected): void
{
    app()->instance('xero', new class($connected)
    {
        public function __construct(private bool $connected) {}

        public function isConnected(): bool
        {
            return $this->connected;
        }
    });
}

it('detects the package through its service provider', function () {
    expect(XeroIntegration::installed())->toBe(class_exists('Dcblogdev\\Xero\\XeroServiceProvider'));
});

it('is never connected when the package is missing, whatever the binding says', function () {
    xeroConnectedStub(true);
    XeroIntegration::$installedUsing = false;

    expect(XeroIntegration::connected())->toBeFalse();
});

it('reads a binding that throws as not connected', function () {
    XeroIntegration::$installedUsing = true;
    app()->instance('xero', new class
    {
        public function isConnected(): bool
        {
            throw new RuntimeException('Xero is unreachable');
        }
    });

    expect(XeroIntegration::connected())->toBeFalse();
});

it('shows the install command instead of Connect Xero when the package is missing', function () {
    XeroIntegration::$installedUsing = false;

    livewire(Integrations::class)
        ->assertOk()
        ->assertSee(__('laravel-crm-filament::labels.integrations.xero_not_installed'))
        ->assertSee(Integrations::XERO_INSTALL_COMMAND)
        ->assertDontSee(__('laravel-crm-filament::labels.actions.connect_xero'));
});

it('offers Connect Xero once the package is installed but not connected', function () {
    XeroIntegration::$installedUsing = true;
    xeroConnectedStub(false);

    livewire(Integrations::class)
        ->assertOk()
        ->assertSee(__('laravel-crm-filament::labels.actions.connect_xero'))
        ->assertDontSee(Integrations::XERO_INSTALL_COMMAND);
});

it('hides Connect Xero on a headless install where core never registered its routes', function () {
    XeroIntegration::$installedUsing = true;
    xeroConnectedStub(false);

    $remaining = new RouteCollection;
    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with((string) $route->getName(), 'laravel-crm.integrations.xero')) {
            $remaining->add($route);
        }
    }
    app('router')->setRoutes($remaining);

    livewire(Integrations::class)
        ->assertOk()
        ->assertDontSee(__('laravel-crm-filament::labels.actions.connect_xero'));
});

it('never references a Dcblogdev class directly outside the gate', function () {
    $offenders = [];

    $files = Finder::create()->files()->name('*.php')
        ->in([dirname(__DIR__, 2) . '/src', dirname(__DIR__, 2) . '/resources']);

    foreach ($files as $file) {
        if ($file->getRealPath() === (new ReflectionClass(XeroIntegration::class))->getFileName()) {
            continue;
        }

        if (str_contains($file->getContents(), 'Dcblogdev')) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});
