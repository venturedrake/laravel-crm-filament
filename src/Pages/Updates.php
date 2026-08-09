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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema as DbSchema;
use Throwable;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Concerns\AuthorizesCrmSettingsPage;
use VentureDrake\LaravelCrmFilament\Resources\Users\UserResource;

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
        'php artisan laravelcrm:update',
    ];

    public ?string $currentVersion = null;

    public ?string $latestVersion = null;

    public ?string $installId = null;

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $systemCheckAlerts = [];

    public function mount(): void
    {
        $this->refreshState();
    }

    protected function refreshState(): void
    {
        $this->currentVersion = (string) (config('laravel-crm.version') ?? '');

        $settings = app()->bound('laravel-crm.settings') ? app('laravel-crm.settings') : null;

        if ($settings) {
            $this->latestVersion = $settings->get('version_latest') ?: null;
            $this->installId = $settings->get('install_id') ?: null;
        }

        // check(), not alerts(): alerts() returns nothing at all when
        // update_notifications is off, and this page is where an operator has
        // deliberately come to ask the question.
        $this->systemCheckAlerts = app()->bound('laravel-crm.system-check')
            ? app('laravel-crm.system-check')->check()
            : [];
    }

    public function getIsUpToDateProperty(): bool
    {
        if (! $this->latestVersion || ! $this->currentVersion) {
            return true;
        }

        return version_compare($this->currentVersion, $this->latestVersion, '>=');
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
            Section::make(__('laravel-crm-filament::labels.updates.installed_version'))
                ->columns(2)
                ->schema([
                    TextEntry::make('currentVersion')
                        ->hiddenLabel()
                        ->state(fn (): string => $this->currentVersion ?: '—')
                        ->badge()
                        ->color('gray'),

                    TextEntry::make('installId')
                        ->label(__('laravel-crm-filament::labels.updates.install_id'))
                        ->state(fn (): ?string => $this->installId)
                        ->copyable()
                        ->placeholder('—'),
                ]),

            Section::make(__('laravel-crm-filament::labels.updates.latest_available'))
                ->schema([
                    TextEntry::make('latestVersion')
                        ->hiddenLabel()
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
                ]),

            Section::make(__('laravel-crm-filament::labels.updates.database_update_required'))
                ->icon('heroicon-o-exclamation-triangle')
                ->iconColor('warning')
                ->visible(fn (): bool => $this->getNeedsDbUpdateProperty())
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

    protected function getHeaderActions(): array
    {
        return [
            $this->checkForUpdatesAction(),
            $this->runUpdateAction(),
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

                Notification::make()
                    ->title($this->getIsUpToDateProperty()
                        ? __('laravel-crm-filament::labels.notifications.update_check_up_to_date')
                        : __('laravel-crm-filament::labels.notifications.update_check_available', ['version' => $this->latestVersion]))
                    ->success()
                    ->send();
            });
    }

    /**
     * Queue `laravelcrm:update`.
     *
     * `--force` is required as of core 2.4.0: the command gained
     * confirmToProceed(), which prompts on an interactive production console —
     * and a queue worker can never answer. Migration and seeder failures are
     * also fatal now rather than warnings.
     */
    protected function runUpdateAction(): Action
    {
        return Action::make('runUpdate')
            ->label(__('laravel-crm-filament::labels.actions.run_update'))
            ->icon('heroicon-o-arrow-down-tray')
            ->requiresConfirmation()
            ->visible(fn (): bool => static::canAccess())
            ->action(function (): void {
                abort_unless(static::canAccess(), 403);

                Artisan::queue('laravelcrm:update', ['--force' => true]);

                // Artisan::queue() discards the exit code, so this cannot claim
                // the update succeeded — only that it was handed to the queue.
                Notification::make()
                    ->title(__('laravel-crm-filament::labels.notifications.update_queued'))
                    ->body(__('laravel-crm-filament::labels.notifications.update_queued_body'))
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

        if (app()->bound('laravel-crm.settings')) {
            app('laravel-crm.settings')->forgetCache();
        }

        if (app()->bound('laravel-crm.system-check')) {
            app('laravel-crm.system-check')->forgetCache();
        }

        return $checked;
    }
}
