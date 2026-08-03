<?php

use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use VentureDrake\LaravelCrmFilament\Concerns\AuthorizesCrmSettingsPage;
use VentureDrake\LaravelCrmFilament\Pages\ActivityFeed;
use VentureDrake\LaravelCrmFilament\Pages\CalendarPage;
use VentureDrake\LaravelCrmFilament\Pages\ClickSendIntegration;
use VentureDrake\LaravelCrmFilament\Pages\GeneralSettings;
use VentureDrake\LaravelCrmFilament\Pages\Integrations;
use VentureDrake\LaravelCrmFilament\Pages\Reminders;
use VentureDrake\LaravelCrmFilament\Pages\Updates;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

/**
 * The seven plugin Pages were entirely ungated before US-004 — every
 * authenticated panel user could edit org-wide settings, read/write Xero and
 * ClickSend credentials, and queue `laravelcrm:update`. These tests lock the
 * permission map in, page by page.
 */
beforeEach(function (): void {
    RoleSeeder::seed();
});

function authzUser(string $namePrefix): User
{
    return User::create([
        'name' => $namePrefix,
        'email' => Str::slug($namePrefix) . '-' . Str::random(8) . '@example.test',
        'password' => bcrypt('secret'),
    ]);
}

/**
 * The seven gated pages and the CRM permission each one requires.
 */
dataset('gated pages', [
    'GeneralSettings' => [GeneralSettings::class, 'view crm settings'],
    'Integrations' => [Integrations::class, 'view crm settings'],
    'ClickSendIntegration' => [ClickSendIntegration::class, 'view crm settings'],
    'Updates' => [Updates::class, 'view crm updates'],
    'ActivityFeed' => [ActivityFeed::class, 'view crm activities'],
    'CalendarPage' => [CalendarPage::class, 'view crm activities'],
    'Reminders' => [Reminders::class, 'view crm tasks'],
]);

dataset('permitted roles', ['Owner', 'Employee']);

it('seeds view crm updates and grants it to Owner and Employee', function (): void {
    expect(Permission::where('name', 'view crm updates')->exists())->toBeTrue();

    foreach (['Owner', 'Admin', 'Manager', 'Employee'] as $roleName) {
        expect(Role::findByName($roleName)->hasPermissionTo('view crm updates'))
            ->toBeTrue("{$roleName} should hold view crm updates");
    }
});

it('uses the AuthorizesCrmSettingsPage concern and declares the mapped permission', function (string $page, string $permission): void {
    expect(class_uses_recursive($page))->toContain(AuthorizesCrmSettingsPage::class);

    $property = new ReflectionProperty($page, 'crmPermission');
    expect($property->isStatic())->toBeTrue();
    expect($property->getType()?->getName())->toBe('string');
    expect($property->getValue())->toBe($permission);
})->with('gated pages');

it('allows a user holding the permission to access the page', function (string $page, string $permission, string $role): void {
    $user = authzUser('Permitted ' . class_basename($page));
    $user->assignRole($role);
    $this->actingAs($user);

    expect($user->hasPermissionTo($permission))->toBeTrue();
    expect($page::canAccess())->toBeTrue();
})->with('gated pages')->with('permitted roles');

it('denies a permission-less user with a 403 on mount', function (string $page, string $permission): void {
    $user = authzUser('Unpermitted ' . class_basename($page));
    $this->actingAs($user);

    expect($user->hasPermissionTo($permission))->toBeFalse();
    expect($page::canAccess())->toBeFalse();

    // Filament's CanAuthorizeAccess aborts 403 from mountCanAuthorizeAccess().
    // Livewire's own test broker hands HttpExceptions to the exception handler
    // instead of rethrowing them, so the 403 has to be observed over HTTP.
    $this->get($page::getUrl())->assertForbidden();
})->with('gated pages');

it('denies a guest', function (string $page): void {
    expect($page::canAccess())->toBeFalse();
})->with('gated pages');

it('hides the navigation entry from a user without the permission', function (string $page): void {
    $this->actingAs(authzUser('No Nav ' . class_basename($page)));

    expect($page::shouldRegisterNavigation())->toBeFalse();
})->with('gated pages');

it('registers navigation for a permitted user on the pages that appear in the sidebar', function (string $page, string $permission, string $role): void {
    $user = authzUser('Nav ' . class_basename($page));
    $user->assignRole($role);
    $this->actingAs($user);

    // ClickSendIntegration and Reminders are reached through sub-navigation, so
    // they opt out of the sidebar entirely regardless of permission.
    $expected = ! in_array($page, [ClickSendIntegration::class, Reminders::class], true);

    expect($page::shouldRegisterNavigation())->toBe($expected);
})->with('gated pages')->with('permitted roles');

it('makes Updates::checkForUpdates unavailable to a permission-less user', function (): void {
    $page = new Updates;

    $this->actingAs(authzUser('No Updates'));
    expect(Updates::canAccess())->toBeFalse();

    $actions = (new ReflectionMethod(Updates::class, 'getHeaderActions'))->invoke($page);
    $checkForUpdates = collect($actions)->firstWhere(fn ($action) => $action->getName() === 'checkForUpdates');

    expect($checkForUpdates)->not->toBeNull();
    expect($checkForUpdates->isVisible())->toBeFalse();
});

it('keeps Updates::checkForUpdates available to a permitted user', function (string $role): void {
    $user = authzUser('Updates ' . $role);
    $user->assignRole($role);
    $this->actingAs($user);

    $actions = (new ReflectionMethod(Updates::class, 'getHeaderActions'))->invoke(new Updates);
    $checkForUpdates = collect($actions)->firstWhere(fn ($action) => $action->getName() === 'checkForUpdates');

    expect(Updates::canAccess())->toBeTrue();
    expect($checkForUpdates->isVisible())->toBeTrue();
})->with('permitted roles');

it('requires edit crm settings to save, not merely view', function (string $page): void {
    $viewer = authzUser('Settings Viewer ' . class_basename($page));
    $viewer->assignRole(settingsViewerRole());
    $this->actingAs($viewer);

    // Read access is granted...
    expect($page::canAccess())->toBeTrue();
    // ...but the write permission is not.
    expect($page::canEditCrmSettings())->toBeFalse();

    $instance = new $page;

    expect((new ReflectionMethod($page, 'getFormActions'))->invoke($instance))->toBe([]);

    try {
        $instance->save();
        $this->fail("{$page}::save() ran without edit crm settings");
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    }
})->with([
    'GeneralSettings' => [GeneralSettings::class],
    'Integrations' => [Integrations::class],
    'ClickSendIntegration' => [ClickSendIntegration::class],
]);

it('lets a user holding edit crm settings through the save guard', function (string $page): void {
    $user = authzUser('Settings Editor ' . class_basename($page));
    $user->assignRole('Owner');
    $this->actingAs($user);

    expect($page::canEditCrmSettings())->toBeTrue();
    expect((new ReflectionMethod($page, 'getFormActions'))->invoke(new $page))->not->toBe([]);
})->with([
    'GeneralSettings' => [GeneralSettings::class],
    'ClickSendIntegration' => [ClickSendIntegration::class],
]);

it('degrades gracefully when the host has never seeded the permission', function (): void {
    $user = authzUser('Unseeded Host');
    $this->actingAs($user);

    Permission::whereIn('name', ['view crm settings', 'edit crm settings', 'view crm updates'])->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Spatie throws PermissionDoesNotExist here; the concern swallows it and
    // falls back to the pre-gating behaviour rather than locking everyone out.
    expect(GeneralSettings::canAccess())->toBeTrue();
    expect(GeneralSettings::canEditCrmSettings())->toBeTrue();
    expect(Updates::canAccess())->toBeTrue();
});

/**
 * A role holding `view crm settings` but not `edit crm settings`.
 */
function settingsViewerRole(): string
{
    $role = Role::findOrCreate('SettingsViewer');
    $role->syncPermissions(['view crm settings']);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return 'SettingsViewer';
}

// ----------------------------------------------------------------------------
// Xero connect / disconnect are writes
//
// Disconnecting tears down the org's Xero connection, and the base route
// behind it carries only `auth.laravel-crm` — no permission check of its own.
// A `view crm settings` holder must not be able to reach either control.
// ----------------------------------------------------------------------------

function integrationsXeroConnected(bool $connected): void
{
    app()->instance('xero', new class($connected)
    {
        public function __construct(private bool $connected) {}

        public function isConnected(): bool
        {
            return $this->connected;
        }

        public function getTenantName(): string
        {
            return 'Test Tenant';
        }

        public function __call($name, $args)
        {
            return null;
        }
    });
}

it('hides Disconnect Xero from a user who may only view settings', function (): void {
    integrationsXeroConnected(true);

    $viewer = authzUser('Xero Viewer');
    $viewer->assignRole(settingsViewerRole());
    $this->actingAs($viewer);

    $page = new Integrations;
    $actions = (new ReflectionMethod(Integrations::class, 'getHeaderActions'))->invoke($page);
    $disconnect = collect($actions)->firstWhere(fn ($action) => $action->getName() === 'disconnectXero');

    expect($disconnect)->not->toBeNull();
    expect($disconnect->isVisible())->toBeFalse();
});

it('shows Disconnect Xero to a user holding edit crm settings', function (): void {
    integrationsXeroConnected(true);

    $editor = authzUser('Xero Editor');
    $editor->assignRole('Owner');
    $this->actingAs($editor);

    $page = new Integrations;
    $actions = (new ReflectionMethod(Integrations::class, 'getHeaderActions'))->invoke($page);
    $disconnect = collect($actions)->firstWhere(fn ($action) => $action->getName() === 'disconnectXero');

    expect($disconnect->isVisible())->toBeTrue();
});

it('gates the Connect Xero call to action on edit crm settings too', function (): void {
    $source = file_get_contents((new ReflectionClass(Integrations::class))->getFileName());

    // The connect CTA sits between its own Action::make() and the ->url()
    // that fires the connection; the gate must be inside that block.
    $cta = Str::between($source, "Action::make('connectXeroCta')", 'xero.connect');

    expect($cta)->toContain('canEditCrmSettings()');
});
