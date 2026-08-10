<?php

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Pages\Updates;
use VentureDrake\LaravelCrmFilament\Support\PanelSystemCheck;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * The Updates page with the network call stubbed, so the outcome of the check
 * can be varied without reaching the version API.
 */
class StubbedCheckUpdates extends Updates
{
    public static bool $succeeds = true;

    protected function fetchLatestVersion(): bool
    {
        return static::$succeeds;
    }
}

/**
 * The Updates page: read-only reporting, and the version_latest gap.
 */
beforeEach(function () {
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'Updates Tester',
        'email' => 'updates-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->givePermissionTo('view crm updates');
    $this->actingAs($this->user->fresh());
});

it('offers only the read-only check, never a run-update action', function () {
    $instance = livewire(Updates::class)->instance();

    $method = new ReflectionMethod(Updates::class, 'getHeaderActions');
    $method->setAccessible(true);

    $names = array_map(fn ($action) => $action->getName(), $method->invoke($instance));

    // Checking the version API and migrating the database are different things,
    // and only the first belongs on an admin page. Upgrades are a deployment
    // step run from the console.
    expect($names)->toBe(['checkForUpdates']);
});

it('never triggers laravelcrm:update from the panel', function () {
    // The page reports; it does not upgrade. `laravelcrm:update` publishes
    // assets, migrates and reseeds the live database, and runs one-shot data
    // backfills — no admin-panel click should be able to start that.
    $source = (string) file_get_contents((new ReflectionClass(Updates::class))->getFileName());

    // Asserted on the code, not just the header actions: no dispatch path of
    // any kind — action, queued job, or direct call.
    expect($source)->not->toContain('Artisan::')
        ->not->toContain('runUpdate');
});

it('populates version_latest itself rather than waiting for core middleware', function () {
    // version_latest is written only by core's Http\Middleware\Settings and
    // UpdateController, both inside core's web group, which a panel-only host
    // never registers — so UPDATE_AVAILABLE could never fire and this page said
    // "no version information" forever.
    $source = (string) file_get_contents((new ReflectionClass(Updates::class))->getFileName());

    expect($source)->toContain("'version_latest'")
        ->toContain("'install_id'")
        ->toContain(Updates::VERSION_API_URL);

    // Emphatically NOT by registering core's Settings middleware on the panel:
    // that fires ~15 updateOrCreate writes on every request plus a blocking
    // Guzzle call every three days, on a live request.
    $plugin = (string) file_get_contents(dirname(__DIR__, 2) . '/src/LaravelCrmPlugin.php');
    $provider = (string) file_get_contents(dirname(__DIR__, 2) . '/src/LaravelCrmFilamentServiceProvider.php');

    expect($plugin . $provider)->not->toContain('LaravelCrm\\Http\\Middleware\\Settings');
});

it('reads version_latest into the page state', function () {
    Setting::updateOrCreate(['name' => 'version_latest'], ['value' => '99.0.0']);
    app('laravel-crm.settings')->forgetCache();

    $instance = livewire(Updates::class)->instance();

    expect($instance->latestVersion)->toBe('99.0.0')
        ->and($instance->isUpToDate)->toBeFalse();
});

it('reports up to date when nothing newer is published', function () {
    Setting::updateOrCreate(['name' => 'version_latest'], ['value' => '0.0.1']);
    app('laravel-crm.settings')->forgetCache();

    expect(livewire(Updates::class)->instance()->isUpToDate)->toBeTrue();
});

it('answers the DB_UPDATE_REQUIRED question even with update notifications off', function () {
    // check(), not alerts(): alerts() returns nothing at all when
    // update_notifications is off, and this page is where an operator has
    // deliberately come to ask.
    config()->set('laravel-crm.update_notifications', false);

    $source = (string) file_get_contents((new ReflectionClass(Updates::class))->getFileName());

    expect($source)->toContain("app('laravel-crm.system-check')->check()")
        ->not->toContain("app('laravel-crm.system-check')->alerts()");

    expect(livewire(Updates::class)->instance()->systemCheckAlerts)->toBeArray();
});

it('no longer renders version_latest_notes unescaped', function () {
    // Nothing in core writes that setting, and its only conceivable writer is
    // a remote HTTP response — a raw HTML sink is not worth keeping for a
    // feature that never shipped. The custom Blade view went with it: the page
    // renders through a schema now, so there is no raw-HTML sink left at all.
    expect(file_exists(dirname(__DIR__, 2) . '/resources/views/clusters/settings/pages/updates.blade.php'))
        ->toBeFalse();

    $source = (string) file_get_contents((new ReflectionClass(Updates::class))->getFileName());

    expect($source)->not->toContain('releaseNotes')
        ->and($source)->not->toContain('version_latest_notes');
});

it('shows the two literal update commands and the upgrade guide link', function () {
    // Both packages move together or core's schema and the panel's
    // expectations diverge, so the order and the wording are the contract.
    // Still two lines: laravelcrm:filament-update runs laravelcrm:update
    // itself, core first, so the operator does not have to know the ordering.
    expect(Updates::UPDATE_COMMANDS)->toBe([
        'composer update venturedrake/laravel-crm venturedrake/laravel-crm-filament',
        'php artisan laravelcrm:filament-update',
    ]);

    $entries = updatesPageEntryStates();

    expect($entries)->toHaveKey('updateCommands')
        ->and($entries['updateCommands'])->toBe(Updates::UPDATE_COMMANDS);

    $source = (string) file_get_contents((new ReflectionClass(Updates::class))->getFileName());
    expect($source)->toContain("config('laravel-crm.upgrade_guide_url')");
});

it('never renders the install ID, but still sends it to the version API', function () {
    Setting::updateOrCreate(['name' => 'install_id'], ['value' => 'abc-123-install']);
    app('laravel-crm.settings')->forgetCache();

    // The install ID identifies this install to the version API. It is of no
    // use to the operator reading the page, so it is not rendered anywhere.
    expect(updatesPageEntryStates())->not->toHaveKey('installId');

    foreach (updatesPageEntryStates() as $state) {
        expect(json_encode($state))->not->toContain('abc-123-install');
    }

    // fetchLatestVersion() still reads and posts it.
    $source = (string) file_get_contents((new ReflectionClass(Updates::class))->getFileName());
    expect($source)->toContain("Setting::where(['name' => 'install_id'])");
});

it('renders through Filament components rather than unstyled markup', function () {
    // This package ships no compiled CSS, and Filament's stylesheet carries
    // only its own fi-* classes — a raw utility class here resolves to nothing
    // and the page renders as a wall of plain text. See CrmBladeStylingTest.
    $components = livewire(Updates::class)
        ->instance()
        ->getSchema('content')
        ->getFlatComponents(withHidden: true);

    $sections = array_filter($components, fn ($c) => $c instanceof Section);

    expect($sections)->not->toBeEmpty();
});

/**
 * The content schema's entries, keyed by name, with their resolved state.
 *
 * @return array<string, mixed>
 */
function updatesPageEntryStates(): array
{
    $states = [];

    foreach (livewire(Updates::class)->instance()->getSchema('content')->getFlatComponents(withHidden: true) as $component) {
        if ($component instanceof TextEntry) {
            $states[$component->getName()] = $component->getState();
        }
    }

    return $states;
}

/**
 * A failed check must say so, even when a previous check left a value behind.
 *
 * Success used to be inferred from `latestVersion === null`. Since a stale
 * `version_latest` survives a failed call, the page cheerfully announced
 * "Version X is available" for a version this run never confirmed — exactly
 * when the operator most needs to hear that the check is broken. The outcome of
 * the call is now returned and used.
 */
it('warns when the version check fails, even with a stale version cached', function () {
    Setting::updateOrCreate(['name' => 'version_latest'], ['value' => '99.99.99']);
    app('laravel-crm.settings')->forgetCache();

    StubbedCheckUpdates::$succeeds = false;

    livewire(StubbedCheckUpdates::class)
        ->callAction('checkForUpdates')
        ->assertNotified(__('laravel-crm-filament::labels.notifications.update_check_failed'));

    // The stale value is left alone rather than being presented as fresh.
    expect(Setting::where('name', 'version_latest')->value('value'))->toBe('99.99.99');
});

it('reports the available version when the check actually succeeds', function () {
    Setting::updateOrCreate(['name' => 'version_latest'], ['value' => '99.99.99']);
    app('laravel-crm.settings')->forgetCache();

    StubbedCheckUpdates::$succeeds = true;

    livewire(StubbedCheckUpdates::class)
        ->callAction('checkForUpdates')
        ->assertNotified(__('laravel-crm-filament::labels.notifications.update_check_available', [
            'version' => '99.99.99',
        ]));
});

it('bounds the version API call so a hung endpoint cannot hang the page', function () {
    // Guzzle defaults both to 0 — "wait forever" — on a call made from a live
    // admin request.
    expect(Updates::VERSION_API_CONNECT_TIMEOUT)->toBeGreaterThan(0)
        ->and(Updates::VERSION_API_TIMEOUT)->toBeGreaterThan(0);

    $source = (string) file_get_contents((new ReflectionClass(Updates::class))->getFileName());

    expect($source)->toContain("'connect_timeout' => self::VERSION_API_CONNECT_TIMEOUT")
        ->toContain("'timeout' => self::VERSION_API_TIMEOUT");
});

it('denies the page to a user without view crm updates', function () {
    $stranger = User::create([
        'name' => 'Stranger',
        'email' => 'stranger-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    // The permission exists (RoleSeeder seeded it) but this user lacks it, so
    // ChecksCrmPermissions returns false rather than failing open.
    $this->actingAs($stranger->fresh());

    expect(Updates::canAccess())->toBeFalse();
});

it('renders the panel version alongside core\'s', function () {
    // The plugin has its own semver line and its own migrations, so its version
    // is a separate fact from core's.
    $entries = updatesPageEntryStates();

    expect($entries)->toHaveKey('panelVersion')
        ->and($entries['panelVersion'])->toBe((string) config('laravel-crm-filament.version'));

    expect($entries)->toHaveKey('currentVersion')
        ->and($entries['currentVersion'])->toBe((string) config('laravel-crm.version'));
});

it('reports both versions in one section, each behind its own product label', function () {
    // Two separate "version" cards invited the reader to take the first one as
    // *the* version. They are two independent semver lines that move together
    // on an upgrade, so they belong side by side — and neither is readable
    // without a label saying which package it is.
    $section = null;

    foreach (livewire(Updates::class)->instance()->getSchema('content')->getFlatComponents(withHidden: true) as $component) {
        if ($component instanceof Section
            && (string) $component->getHeading() === __('laravel-crm-filament::labels.updates.installed_versions')) {
            $section = $component;
        }
    }

    expect($section)->not->toBeNull();

    $labelled = [];

    foreach ($section->getChildSchema()->getFlatComponents(withHidden: true) as $component) {
        if ($component instanceof TextEntry) {
            $labelled[(string) $component->getLabel()] = $component->getState();
        }
    }

    expect($labelled)->toBe([
        __('laravel-crm-filament::labels.updates.laravel_crm') => (string) config('laravel-crm.version'),
        __('laravel-crm-filament::labels.updates.filament_plugin') => (string) config('laravel-crm-filament.version'),
    ]);

    // The labels are product names, so they read the same in every locale.
    expect(__('laravel-crm-filament::labels.updates.laravel_crm'))->toBe('Laravel CRM')
        ->and(__('laravel-crm-filament::labels.updates.filament_plugin'))->toBe('Filament Plugin');

    // And it reaches the DOM. Asserting on schema state alone is how the
    // system-check banner shipped rendering an icon and a dismiss button with
    // no sentence between them — the content was right and the component
    // never echoed it.
    livewire(Updates::class)
        ->assertSee(__('laravel-crm-filament::labels.updates.installed_versions'))
        ->assertSee('Laravel CRM')
        ->assertSee('Filament Plugin')
        ->assertSee((string) config('laravel-crm-filament.version'));
});

it('reads the panel\'s latest release from Packagist, not core\'s version API', function () {
    // VERSION_API_URL is core's endpoint and only knows core's releases, so it
    // has no answer to give about this package. Packagist is where `composer
    // update` reads the same answer from, so the page cannot disagree with the
    // command it tells you to run.
    expect(Updates::PACKAGIST_VERSION_URL)->toContain(Updates::PACKAGIST_PACKAGE)
        ->and(Updates::PACKAGIST_PACKAGE)->toBe('venturedrake/laravel-crm-filament')
        ->and(Updates::PANEL_VERSION_LATEST_SETTING)->toBe('crm_filament_version_latest');

    $source = (string) file_get_contents((new ReflectionClass(Updates::class))->getFileName());

    // Bounded, like core's call — Guzzle defaults both timeouts to "forever".
    expect(substr_count($source, "'connect_timeout' => self::VERSION_API_CONNECT_TIMEOUT"))->toBe(2)
        ->and(substr_count($source, "'timeout' => self::VERSION_API_TIMEOUT"))->toBe(2);

    // A plain GET. Core's check POSTs the install id, app name, URL and user
    // count; nothing about this install should leave the host on this one.
    expect($source)->toContain("request('GET', self::PACKAGIST_VERSION_URL");
});

it('renders the panel\'s latest release beside core\'s', function () {
    Setting::updateOrCreate(['name' => 'version_latest'], ['value' => '2.4.0']);
    Setting::updateOrCreate(
        ['name' => Updates::PANEL_VERSION_LATEST_SETTING],
        ['value' => '9.9.9'],
    );
    app('laravel-crm.settings')->forgetCache();

    $instance = livewire(Updates::class)->instance();

    expect($instance->panelLatestVersion)->toBe('9.9.9')
        ->and($instance->isPanelUpToDate)->toBeFalse();

    expect(updatesPageEntryStates())->toHaveKey('panelLatestVersion');

    livewire(Updates::class)->assertSee('9.9.9');
});

it('does not call a version behind the installed one an available update', function () {
    // A host tracking dev-develop runs ahead of the newest tag on Packagist —
    // which is exactly the state this repo is in. `>=`, not `==`.
    Setting::updateOrCreate(
        ['name' => Updates::PANEL_VERSION_LATEST_SETTING],
        ['value' => '0.0.1'],
    );
    app('laravel-crm.settings')->forgetCache();

    expect(livewire(Updates::class)->instance()->isPanelUpToDate)->toBeTrue();
});

it('picks the highest stable release out of a Packagist version list', function () {
    $method = new ReflectionMethod(Updates::class, 'highestStableVersion');
    $method->setAccessible(true);

    $page = livewire(Updates::class)->instance();

    // The `v` prefix has to go: version_compare() does not know it as a
    // prefix, it treats it as an unknown string part that sorts below digits,
    // so v1.10.0 vs 1.9.0 would come out backwards.
    expect($method->invoke($page, ['v1.0.0', 'v1.10.0', 'v1.9.0']))->toBe('1.10.0');

    // Pre-releases and branch aliases are not what `composer update` would
    // install on a prefer-stable host, so offering one sends the operator
    // chasing a release they cannot get.
    expect($method->invoke($page, ['dev-main', 'v2.0.0-beta1', 'v1.2.0']))->toBe('1.2.0');

    expect($method->invoke($page, ['dev-main']))->toBeNull()
        ->and($method->invoke($page, []))->toBeNull();
});

it('hides the database section when neither database is behind its code', function () {
    Setting::updateOrCreate(
        ['name' => PanelSystemCheck::DB_VERSION_SETTING],
        ['value' => (string) config('laravel-crm-filament.version'), 'global' => 1],
    );
    app('laravel-crm-filament.system-check')->forgetCache();

    $instance = livewire(Updates::class)->instance();

    expect($instance->needsPanelDbUpdate)->toBeFalse();
    expect(updatesPageVisibleSectionHeadings())
        ->not->toContain(__('laravel-crm-filament::labels.updates.database_update_required'));
});

it('shows one database section, driven by the panel check as well as core\'s', function () {
    // Nothing has ever stamped crm_filament_db_version, which is the state
    // every host upgrading into this release starts in. There is no second,
    // panel-specific section — the fix is the same command either way, so a
    // pair of near-identical warnings carried no extra information. But this
    // one still has to fire on a panel-only shortfall, or the page would stay
    // silent while the banner reported it.
    Setting::query()->where('name', PanelSystemCheck::DB_VERSION_SETTING)->delete();
    app('laravel-crm-filament.system-check')->forgetCache();

    $instance = livewire(Updates::class)->instance();

    expect($instance->needsDbUpdate)->toBeFalse()
        ->and($instance->needsPanelDbUpdate)->toBeTrue();

    $headings = updatesPageVisibleSectionHeadings();

    expect($headings)->toContain(__('laravel-crm-filament::labels.updates.database_update_required'));

    // Exactly one, not two.
    expect(array_filter(
        $headings,
        fn (string $heading): bool => $heading === __('laravel-crm-filament::labels.updates.database_update_required'),
    ))->toHaveCount(1);

    // And the body names the command that fixes both.
    livewire(Updates::class)->assertSee('php artisan laravelcrm:filament-update');
});

it('answers the panel database question even with update notifications off', function () {
    // check(), not alerts(), for the same reason core's section uses check():
    // this page is where an operator has deliberately come to ask.
    config()->set('laravel-crm.update_notifications', false);

    $source = (string) file_get_contents((new ReflectionClass(Updates::class))->getFileName());

    expect($source)->toContain("app('laravel-crm-filament.system-check')->check()")
        ->not->toContain("app('laravel-crm-filament.system-check')->alerts()");

    Setting::query()->where('name', PanelSystemCheck::DB_VERSION_SETTING)->delete();

    expect(livewire(Updates::class)->instance()->needsPanelDbUpdate)->toBeTrue();
});

it('degrades quietly on a host where the panel check is not bound', function () {
    // Both system-check lookups are guarded, so a partially-booted container
    // cannot take the page down with it.
    $source = (string) file_get_contents((new ReflectionClass(Updates::class))->getFileName());

    expect($source)->toContain("app()->bound('laravel-crm-filament.system-check')");
});

/**
 * The headings of the content schema's currently-visible sections.
 *
 * @return array<int, string>
 */
function updatesPageVisibleSectionHeadings(): array
{
    $headings = [];

    foreach (livewire(Updates::class)->instance()->getSchema('content')->getFlatComponents() as $component) {
        if ($component instanceof Section) {
            $headings[] = (string) $component->getHeading();
        }
    }

    return $headings;
}
