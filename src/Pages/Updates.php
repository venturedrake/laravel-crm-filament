<?php

namespace VentureDrake\LaravelCrmFilament\Pages;

use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Schema as DbSchema;
use Throwable;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Concerns\AuthorizesCrmSettingsPage;
use VentureDrake\LaravelCrmFilament\Resources\Users\UserResource;
use VentureDrake\LaravelCrmFilament\Support\PanelSystemCheck;

class Updates extends Page
{
    use AuthorizesCrmSettingsPage;

    protected static string $crmPermission = 'view crm updates';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static string | \UnitEnum | null $navigationGroup = 'Settings';

    protected static ?string $title = 'Updates';

    protected static ?string $slug = 'updates';

    protected static ?int $navigationSort = 200;

    /**
     * The version API core's UpdateController posts to.
     */
    public const VERSION_API_URL = 'https://api.laravelcrm.com/api/v2/public/version';

    /**
     * Where the panel's own latest release is looked up.
     *
     * Packagist rather than VERSION_API_URL: that endpoint is core's, and it
     * only knows core's releases — there is nothing to ask it about this
     * package. Packagist is where `composer update` reads the same answer
     * from, so it cannot disagree with the command this page tells you to run.
     *
     * A plain GET of a public metadata file, unlike core's check, which POSTs
     * the install id, app name, URL and user count. Nothing about this install
     * leaves the host on this call.
     */
    public const PACKAGIST_VERSION_URL = 'https://repo.packagist.org/p2/venturedrake/laravel-crm-filament.json';

    public const PACKAGIST_PACKAGE = 'venturedrake/laravel-crm-filament';

    /**
     * Where the answer is cached between checks, mirroring core's
     * `version_latest`. Written only by this page.
     */
    public const PANEL_VERSION_LATEST_SETTING = 'crm_filament_version_latest';

    /**
     * Seconds. Guzzle defaults both of these to 0, meaning "no limit".
     */
    public const VERSION_API_CONNECT_TIMEOUT = 5;

    public const VERSION_API_TIMEOUT = 10;

    /**
     * The two commands an operator has to run, in order. Asserted verbatim by
     * UpdatesPageTest — both packages move together or core's schema and the
     * panel's expectations diverge.
     *
     * @var array<int, string>
     */
    public const UPDATE_COMMANDS = [
        'composer update venturedrake/laravel-crm venturedrake/laravel-crm-filament',
        'php artisan laravelcrm:filament-update',
    ];

    public ?string $currentVersion = null;

    public ?string $panelVersion = null;

    public ?string $latestVersion = null;

    public ?string $panelLatestVersion = null;

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $systemCheckAlerts = [];

    /**
     * The panel's own database-behind-code check, kept apart from core's.
     * Both can be outstanding at once and they are reported separately.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $panelSystemCheckAlerts = [];

    public function mount(): void
    {
        $this->refreshState();
    }

    protected function refreshState(): void
    {
        $this->currentVersion = (string) (config('laravel-crm.version') ?? '');
        $this->panelVersion = (string) (config('laravel-crm-filament.version') ?? '');

        $settings = app()->bound('laravel-crm.settings') ? app('laravel-crm.settings') : null;

        if ($settings) {
            $this->latestVersion = $settings->get('version_latest') ?: null;
            $this->panelLatestVersion = $settings->get(self::PANEL_VERSION_LATEST_SETTING) ?: null;
        }

        // check(), not alerts(): alerts() returns nothing at all when
        // update_notifications is off, and this page is where an operator has
        // deliberately come to ask the question.
        $this->systemCheckAlerts = app()->bound('laravel-crm.system-check')
            ? app('laravel-crm.system-check')->check()
            : [];

        $this->panelSystemCheckAlerts = app()->bound('laravel-crm-filament.system-check')
            ? app('laravel-crm-filament.system-check')->check()
            : [];
    }

    public function getIsUpToDateProperty(): bool
    {
        if (! $this->latestVersion || ! $this->currentVersion) {
            return true;
        }

        return version_compare($this->currentVersion, $this->latestVersion, '>=');
    }

    /**
     * The same question for the panel.
     *
     * "Up to date" is `>=`, not `==`, on purpose: a host tracking `dev-develop`
     * runs a version ahead of the newest tag on Packagist, and telling it an
     * older release is "available" would be nonsense.
     */
    public function getIsPanelUpToDateProperty(): bool
    {
        if (! $this->panelLatestVersion || ! $this->panelVersion) {
            return true;
        }

        return version_compare($this->panelVersion, $this->panelLatestVersion, '>=');
    }

    public function getNeedsDbUpdateProperty(): bool
    {
        foreach ($this->systemCheckAlerts as $alert) {
            if (($alert['type'] ?? null) === 'db_update_required') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether *this package's* migrations are outstanding, which core's check
     * knows nothing about — it compares core's version against core's marker
     * and counts core's migration files only.
     */
    public function getNeedsPanelDbUpdateProperty(): bool
    {
        foreach ($this->panelSystemCheckAlerts as $alert) {
            if (($alert['type'] ?? null) === PanelSystemCheck::PANEL_DB_UPDATE_REQUIRED) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rendered through a Filament schema rather than a hand-written Blade view.
     *
     * This package ships no compiled CSS, and Filament's own stylesheet contains
     * only its `fi-*` classes — not a general Tailwind utility set. A raw
     * `class="text-sm text-gray-500"` in a package view therefore resolves to
     * nothing at all, which is how this page came to render as unstyled text.
     * Entries and Sections carry their own styling, so there is nothing here for
     * a host's CSS build to have to know about.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            // Both packages in one section, each behind its own label. They are
            // two independent semver lines that move together on an upgrade, so
            // reading them side by side is the whole point — two separate
            // "version" cards invited the reader to take the first one as *the*
            // version.
            //
            // Install ID is deliberately not shown. It identifies this install
            // to the version API and is of no use to the operator reading this
            // page; it is still sent by fetchLatestVersion().
            Section::make(__('laravel-crm-filament::labels.updates.installed_versions'))
                ->columns(2)
                ->schema([
                    TextEntry::make('currentVersion')
                        ->label(__('laravel-crm-filament::labels.updates.laravel_crm'))
                        ->state(fn (): string => $this->currentVersion ?: '—')
                        ->badge()
                        ->color('gray'),

                    // The panel ships its own semver line and its own
                    // migrations, so its "latest available" comes from its own
                    // source — Packagist, not core's version API. See
                    // PACKAGIST_VERSION_URL.
                    TextEntry::make('panelVersion')
                        ->label(__('laravel-crm-filament::labels.updates.filament_plugin'))
                        ->state(fn (): string => $this->panelVersion ?: '—')
                        ->badge()
                        ->color('gray'),
                ]),

            // Laid out to match "Installed versions" above, so the two cards
            // read as a grid: same order, same labels, one column per package.
            Section::make(__('laravel-crm-filament::labels.updates.latest_available'))
                ->columns(2)
                ->schema([
                    TextEntry::make('latestVersion')
                        ->label(__('laravel-crm-filament::labels.updates.laravel_crm'))
                        ->state(fn (): string => $this->latestVersion ?: __('laravel-crm-filament::labels.updates.no_version_information'))
                        ->badge(fn (): bool => filled($this->latestVersion))
                        ->color(fn (): string => match (true) {
                            blank($this->latestVersion) => 'gray',
                            $this->getIsUpToDateProperty() => 'success',
                            default => 'warning',
                        })
                        ->helperText(fn (): ?string => match (true) {
                            blank($this->latestVersion) => null,
                            $this->getIsUpToDateProperty() => __('laravel-crm-filament::labels.updates.up_to_date'),
                            default => __('laravel-crm-filament::labels.updates.newer_available'),
                        }),

                    TextEntry::make('panelLatestVersion')
                        ->label(__('laravel-crm-filament::labels.updates.filament_plugin'))
                        ->state(fn (): string => $this->panelLatestVersion ?: __('laravel-crm-filament::labels.updates.no_version_information'))
                        ->badge(fn (): bool => filled($this->panelLatestVersion))
                        ->color(fn (): string => match (true) {
                            blank($this->panelLatestVersion) => 'gray',
                            $this->getIsPanelUpToDateProperty() => 'success',
                            default => 'warning',
                        })
                        ->helperText(fn (): ?string => match (true) {
                            blank($this->panelLatestVersion) => null,
                            $this->getIsPanelUpToDateProperty() => __('laravel-crm-filament::labels.updates.up_to_date'),
                            default => __('laravel-crm-filament::labels.updates.newer_available'),
                        }),
                ]),

            // One section for both checks, not one each. The fix is the same
            // command either way — the body text names it — so splitting them
            // gave the reader two near-identical warnings and no extra
            // information. Still driven by both, or a panel-only shortfall
            // would leave this page silent while the banner shouted.
            Section::make(__('laravel-crm-filament::labels.updates.database_update_required'))
                ->icon('heroicon-o-exclamation-triangle')
                ->iconColor('warning')
                ->visible(fn (): bool => $this->getNeedsDbUpdateProperty() || $this->getNeedsPanelDbUpdateProperty())
                ->schema([
                    TextEntry::make('databaseUpdateRequired')
                        ->hiddenLabel()
                        ->state(__('laravel-crm-filament::labels.updates.database_update_required_body')),
                ]),

            Section::make(__('laravel-crm-filament::labels.updates.how_to_update'))
                ->schema([
                    TextEntry::make('howToUpdateIntro')
                        ->hiddenLabel()
                        ->state(__('laravel-crm-filament::labels.updates.how_to_update_intro')),

                    // Monospace and copyable — the two commands are the whole
                    // point of this section. Not CodeEntry: that needs phiki,
                    // which is not installed and is not worth a dependency for
                    // two lines of shell.
                    TextEntry::make('updateCommands')
                        ->hiddenLabel()
                        ->state(self::UPDATE_COMMANDS)
                        ->listWithLineBreaks()
                        ->fontFamily(FontFamily::Mono)
                        ->copyable()
                        ->copyableState(implode(PHP_EOL, self::UPDATE_COMMANDS)),

                    Actions::make([
                        Action::make('upgradeGuide')
                            ->label(__('laravel-crm-filament::labels.updates.upgrade_guide'))
                            ->icon('heroicon-o-arrow-top-right-on-square')
                            ->link()
                            ->url(fn (): string => (string) config('laravel-crm.upgrade_guide_url'))
                            ->openUrlInNewTab(),
                    ]),
                ]),
        ]);
    }

    /**
     * Check-for-updates only.
     *
     * There is deliberately no "Run update" action here.
     * `laravelcrm:filament-update` runs `laravelcrm:update` — which publishes
     * assets, migrates and reseeds the live database and performs a set of
     * one-shot data backfills — and then publishes and runs this package's own
     * migrations. That is a deployment step, taken by an operator with a backup
     * and a console, not a button on an admin page behind a generic "are you
     * sure?" modal. The page tells you the two commands to run instead.
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->checkForUpdatesAction(),
        ];
    }

    /**
     * Ask the version API what the latest release is.
     *
     * `version_latest` is written by exactly two things in core —
     * Http\Middleware\Settings and UpdateController — both of which live in
     * core's web group, which a panel-only host never registers. So on a
     * headless install nothing ever populated it, UPDATE_AVAILABLE could never
     * fire, and this page said "no version information" forever.
     *
     * Deliberately *not* fixed by registering core's Settings middleware on the
     * panel: it fires ~15 updateOrCreate writes on every request plus a
     * blocking Guzzle call every three days, on a live request.
     */
    protected function checkForUpdatesAction(): Action
    {
        return Action::make('checkForUpdates')
            ->label(__('laravel-crm-filament::labels.actions.check_for_updates'))
            ->icon('heroicon-o-arrow-path')
            ->visible(fn (): bool => static::canAccess())
            ->action(function (): void {
                abort_unless(static::canAccess(), 403);

                // The outcome of the call itself, not `latestVersion === null`.
                // A stale `version_latest` from a previous check survives a
                // failed one, so inferring success from a non-null property
                // reported "Version X is available" for a version this run
                // never confirmed — precisely when the host most needs telling
                // the check is broken.
                $checked = $this->fetchLatestVersion();

                $this->refreshState();

                if (! $checked || $this->latestVersion === null) {
                    Notification::make()
                        ->title(__('laravel-crm-filament::labels.notifications.update_check_failed'))
                        ->body(__('laravel-crm-filament::labels.notifications.update_check_failed_body'))
                        ->warning()
                        ->send();

                    return;
                }

                // Both packages, or the toast contradicts the page: core can be
                // current while the panel is a release behind, and "You are
                // running the latest version" over a row reading "A newer
                // version is available" is worse than saying nothing.
                Notification::make()
                    ->title($this->getIsUpToDateProperty() && $this->getIsPanelUpToDateProperty()
                        ? __('laravel-crm-filament::labels.notifications.update_check_up_to_date')
                        : __('laravel-crm-filament::labels.notifications.update_check_available', [
                            'version' => $this->getIsUpToDateProperty()
                                ? $this->panelLatestVersion
                                : $this->latestVersion,
                        ]))
                    ->success()
                    ->send();
            });
    }

    /**
     * Mirrors core's UpdateController::index(): stamp `version`, POST to the
     * version API, persist `install_id` and `version_latest`.
     *
     * Returns whether the API answered. The caller needs that separately from
     * the resulting `version_latest`, which may be left over from an earlier
     * successful check.
     */
    protected function fetchLatestVersion(): bool
    {
        $versionSetting = Setting::updateOrCreate(
            ['name' => 'version'],
            ['value' => config('laravel-crm.version')],
        );

        $installIdSetting = Setting::where(['name' => 'install_id'])->first();

        $checked = false;

        try {
            $userCount = 1;

            if (DbSchema::hasColumn('users', 'crm_access')) {
                $model = UserResource::getModel();
                $userCount = max(1, (int) $model::query()->where('crm_access', 1)->count());
            }

            $response = (new Client)->request('POST', self::VERSION_API_URL, [
                // Bounded explicitly: Guzzle defaults both to 0 (wait forever),
                // and this call is made from a live admin request.
                'connect_timeout' => self::VERSION_API_CONNECT_TIMEOUT,
                'timeout' => self::VERSION_API_TIMEOUT,
                'json' => [
                    'id' => $installIdSetting->value ?? null,
                    'name' => config('app.name'),
                    'url' => config('app.url'),
                    'env' => config('app.env'),
                    'version' => config('laravel-crm.version'),
                    'server_ip' => request()->server('SERVER_ADDR'),
                    'user_ip' => request()->ip(),
                    'user_count' => $userCount,
                ],
            ]);

            $body = json_decode((string) $response->getBody());

            if (isset($body->id) && ! $installIdSetting) {
                Setting::create(['name' => 'install_id', 'value' => $body->id]);
            }

            if (isset($body->version)) {
                Setting::updateOrCreate(
                    ['name' => 'version_latest'],
                    ['value' => $body->version],
                );

                $checked = true;
            }
        } catch (Throwable) {
            // A version check must never break the page it is rendered on.
        }

        $versionSetting?->forceFill(['updated_at' => Carbon::now()])->save();

        // Best effort, and deliberately not folded into $checked: this is a
        // different service, and core's answer is the one the "could not check"
        // warning is about. A Packagist outage should not report the whole
        // check as broken when core's half succeeded.
        $this->fetchLatestPanelVersion();

        if (app()->bound('laravel-crm.settings')) {
            app('laravel-crm.settings')->forgetCache();
        }

        foreach (['laravel-crm.system-check', 'laravel-crm-filament.system-check'] as $binding) {
            if (app()->bound($binding)) {
                app($binding)->forgetCache();
            }
        }

        return $checked;
    }

    /**
     * Ask Packagist for this package's newest published release and persist it.
     *
     * Returns whether an answer was obtained. Never throws: a version check
     * must not break the page it is rendered on, and this one is the second of
     * two network calls on a single user-initiated action.
     */
    protected function fetchLatestPanelVersion(): bool
    {
        try {
            $response = (new Client)->request('GET', self::PACKAGIST_VERSION_URL, [
                // Bounded for the same reason core's call is: Guzzle defaults
                // both to 0, meaning "wait forever", on a live admin request.
                'connect_timeout' => self::VERSION_API_CONNECT_TIMEOUT,
                'timeout' => self::VERSION_API_TIMEOUT,
                'headers' => ['Accept' => 'application/json'],
            ]);

            $body = json_decode((string) $response->getBody(), true);

            $versions = $body['packages'][self::PACKAGIST_PACKAGE] ?? null;

            if (! is_array($versions)) {
                return false;
            }

            $latest = $this->highestStableVersion(array_column($versions, 'version'));

            if ($latest === null) {
                return false;
            }

            Setting::updateOrCreate(
                ['name' => self::PANEL_VERSION_LATEST_SETTING],
                ['value' => $latest],
            );

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The highest stable release in a Packagist version list.
     *
     * Stable only: Packagist returns `dev-main`, `1.2.0-beta1` and friends
     * alongside tags, and none of those is what `composer update` on a
     * default `prefer-stable` host would install — offering one as "latest
     * available" would send an operator chasing a release they cannot get.
     *
     * The leading `v` goes too. `version_compare()` does not recognise it as a
     * prefix; it treats it as an unknown string part that happens to sort
     * below digits, so `v1.10.0` vs `1.9.0` would come out backwards.
     *
     * @param  array<int, mixed>  $versions
     */
    protected function highestStableVersion(array $versions): ?string
    {
        $stable = [];

        foreach ($versions as $version) {
            if (! is_string($version)) {
                continue;
            }

            $normalised = ltrim($version, 'vV');

            if (preg_match('/^\d+\.\d+\.\d+$/', $normalised) !== 1) {
                continue;
            }

            $stable[] = $normalised;
        }

        if ($stable === []) {
            return null;
        }

        usort($stable, static fn (string $a, string $b): int => version_compare($a, $b));

        return end($stable) ?: null;
    }
}
