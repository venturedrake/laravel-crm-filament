<?php

use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Livewire\SystemCheckBanner;
use VentureDrake\LaravelCrmFilament\Pages\Updates;
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
    app('laravel-crm.system-check')->forgetCache();
});

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

    $expected = app('laravel-crm.system-check')->signature();

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

    $dismissed = app('laravel-crm.system-check')->signature();

    livewire(SystemCheckBanner::class)->call('dismiss');

    app('laravel-crm.system-check')->forgetCache();

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
    app('laravel-crm.system-check')->forgetCache();

    expect(app('laravel-crm.system-check')->signature())->toBe($dismissed);
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
