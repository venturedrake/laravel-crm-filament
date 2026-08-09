<?php

namespace VentureDrake\LaravelCrmFilament\Pages;

use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
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

    protected string $view = 'laravel-crm-filament::clusters.settings.pages.updates';

    /**
     * The version API core's UpdateController posts to.
     */
    public const VERSION_API_URL = 'https://api.laravelcrm.com/api/v2/public/version';

    /**
     * Seconds. Guzzle defaults both of these to 0, meaning "no limit".
     */
    public const VERSION_API_CONNECT_TIMEOUT = 5;

    public const VERSION_API_TIMEOUT = 10;

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

            if (Schema::hasColumn('users', 'crm_access')) {
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
