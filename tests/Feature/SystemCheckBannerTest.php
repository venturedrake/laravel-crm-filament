<?php

use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Livewire\SystemCheckBanner;
use VentureDrake\LaravelCrmFilament\Pages\Updates;
use VentureDrake\LaravelCrmFilament\Support\PanelSystemCheck;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * The system-check banner: who sees it, and what a dismissal is allowed to
 * persist.
 */
beforeEach(function () {
    RoleSeeder::seed();

    config()->set('laravel-crm.update_notifications', true);

    // An update is available, so there is something to render.
    Setting::updateOrCreate(['name' => 'version'], ['value' => '2.4.0']);
    Setting::updateOrCreate(['name' => 'version_latest'], ['value' => '99.0.0']);

    app('laravel-crm.settings')->forgetCache();
    forgetBannerCheckCaches();
});

/**
 * Both checks, probed rather than assumed.
 *
 * `laravel-crm.system-check` only exists in laravel-crm 2.4+, and composer.lock
 * pins an older one here — which is exactly the host shape the banner has to
 * survive, so the tests run against it rather than around it.
 */
function forgetBannerCheckCaches(): void
{
    foreach (['laravel-crm.system-check', 'laravel-crm-filament.system-check'] as $binding) {
        if (app()->bound($binding)) {
            app($binding)->forgetCache();
        }
    }
}

/**
 * The signature the banner actually persists on dismiss — the combined one.
 */
function bannerCombinedSignature(): ?string
{
    return livewire(SystemCheckBanner::class)->instance()->combinedSignature();
}

function bannerUser(array $permissions = ['view crm updates']): User
{
    $user = User::create([
        'name' => 'Banner Tester',
        'email' => 'banner-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

it('renders the alerts to a user holding view crm updates', function () {
    $this->actingAs(bannerUser());

    $component = livewire(SystemCheckBanner::class);

    expect($component->instance()->alerts)->not->toBeEmpty();
});

it('renders nothing for a user without view crm updates', function () {
    // can(), not the fail-open ChecksCrmPermissions trait: for a settings page
    // failing open means "show the page", but here it would mean showing a
    // scary system alert to somebody with no way to act on it.
    $this->actingAs(bannerUser([]));

    expect(livewire(SystemCheckBanner::class)->instance()->alerts)->toBeEmpty();
});

it('renders nothing for a guest', function () {
    expect(livewire(SystemCheckBanner::class)->instance()->alerts)->toBeEmpty();
});

it('persists a server-computed signature on dismiss, never the posted one', function () {
    $user = bannerUser();
    $this->actingAs($user);

    $expected = bannerCombinedSignature();

    expect($expected)->not->toBeNull();

    livewire(SystemCheckBanner::class)
        // Every public Livewire property is client-writable. Posting a value
        // that the server then stored would let a caller pin the banner shut
        // for good.
        ->set('signature', 'attacker-supplied')
        ->call('dismiss');

    $stored = app('laravel-crm.settings')->getForUser($user->getKey(), SystemCheckBanner::DISMISS_SETTING);

    expect($stored)->toBe($expected)
        ->and($stored)->not->toBe('attacker-supplied');
});

it('stays hidden once dismissed, and comes back when the alerts change', function () {
    $user = bannerUser();
    $this->actingAs($user);

    $dismissed = bannerCombinedSignature();

    livewire(SystemCheckBanner::class)->call('dismiss');

    forgetBannerCheckCaches();

    expect(livewire(SystemCheckBanner::class)->instance()->alerts)->toBeEmpty();

    // The dismissal is keyed on the alert set's signature, so a stale one
    // stops suppressing the banner as soon as the alerts change.
    app('laravel-crm.settings')->setForUser(
        $user->getKey(),
        SystemCheckBanner::DISMISS_SETTING,
        'a-signature-from-an-older-alert-set',
    );
    Cache::flush();
    app('laravel-crm.settings')->forgetCache();
    forgetBannerCheckCaches();

    expect(bannerCombinedSignature())->toBe($dismissed);
    expect(livewire(SystemCheckBanner::class)->instance()->alerts)->not->toBeEmpty();
});

it('403s a dismiss from an unauthorized caller', function () {
    // dismiss() is a public Livewire method, so it is callable straight from
    // the client whether or not the component ever rendered anything.
    $user = bannerUser([]);
    $this->actingAs($user);

    livewire(SystemCheckBanner::class)
        ->call('dismiss')
        ->assertStatus(403);

    expect(app('laravel-crm.settings')->getForUser($user->getKey(), SystemCheckBanner::DISMISS_SETTING))
        ->toBeNull();
});

it('shares core\'s dismissal key so a dismissal carries across both UIs', function () {
    expect(SystemCheckBanner::DISMISS_SETTING)
        ->toBe(VentureDrake\LaravelCrm\Livewire\SystemCheckBanner::DISMISS_SETTING);
});

it('links to the panel Updates page, not core\'s gated route', function () {
    $this->actingAs(bannerUser());

    // route('laravel-crm.updates.index') does not exist on a headless install,
    // which is exactly where this banner matters most.
    expect(SystemCheckBanner::updatesUrl())->toBe(Updates::getUrl());
});

it('is registered as a CONTENT_START render hook rather than a dashboard widget', function () {
    // A widget attaches per page and renders inside the content grid, which is
    // the wrong place for a system-wide alert.
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/LaravelCrmPlugin.php');

    expect($source)->toContain('PanelsRenderHook::CONTENT_START')
        ->and($source)->toContain('SystemCheckBanner::NAME');

    expect(PanelsRenderHook::CONTENT_START)->toBeString();
});

it('renders the panel\'s own database alert', function () {
    // Core's check knows nothing about this package's migrations or its version
    // line, so without this the panel could be a whole release behind with
    // nothing anywhere saying so.
    Setting::query()->where('name', PanelSystemCheck::DB_VERSION_SETTING)->delete();
    forgetBannerCheckCaches();

    $this->actingAs(bannerUser());

    $types = array_column(livewire(SystemCheckBanner::class)->instance()->alerts, 'type');

    expect($types)->toContain(PanelSystemCheck::PANEL_DB_UPDATE_REQUIRED);
});

it('names the panel command in the panel alert, not core\'s', function () {
    // `laravelcrm:update` migrates core and never touches this package's
    // migrations, so telling the operator to run it would be telling them to
    // run the wrong thing.
    $this->actingAs(bannerUser());

    $component = livewire(SystemCheckBanner::class);
    $html = implode(' ', array_column($component->viewData('messages'), 'html'));

    expect($html)->toContain('php artisan laravelcrm:filament-update');
});

it('fingerprints both checks, so dismissing does not swallow later panel alerts', function () {
    $user = bannerUser();
    $this->actingAs($user);

    Setting::query()->where('name', PanelSystemCheck::DB_VERSION_SETTING)->delete();
    forgetBannerCheckCaches();

    $withPanelAlert = bannerCombinedSignature();

    livewire(SystemCheckBanner::class)->call('dismiss');
    forgetBannerCheckCaches();

    expect(livewire(SystemCheckBanner::class)->instance()->alerts)->toBeEmpty();

    // The panel half of the alert set changes — the marker gets stamped — and
    // the banner has to come back rather than stay pinned shut by a signature
    // that only ever covered core's half.
    Setting::create([
        'name' => PanelSystemCheck::DB_VERSION_SETTING,
        'value' => (string) config('laravel-crm-filament.version'),
        'global' => 1,
    ]);
    Cache::flush();
    app('laravel-crm.settings')->forgetCache();
    forgetBannerCheckCaches();

    expect(bannerCombinedSignature())->not->toBe($withPanelAlert);
});

it('uses one combined-signature helper for both resolve and dismiss', function () {
    // dismiss() recomputes server-side rather than trusting the client-writable
    // property, and it has to recompute the *same* value resolve() compares
    // against or a dismissal never sticks.
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Livewire/SystemCheckBanner.php');

    expect(substr_count($source, '$this->combinedSignature('))->toBe(2);
    expect($source)->not->toContain("app('laravel-crm.system-check')->signature()");
});

it('survives a host whose core package predates the system check binding', function () {
    // laravel-crm 2.4 introduced `laravel-crm.system-check`. The banner is most
    // useful precisely on hosts that are behind, so an unbound core check has
    // to degrade to "report what we can see", not fatal.
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Livewire/SystemCheckBanner.php');

    expect($source)->toContain('app()->bound($binding)');
});
