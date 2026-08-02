<?php

namespace VentureDrake\LaravelCrmFilament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use VentureDrake\LaravelCrmFilament\Concerns\AuthorizesCrmSettingsPage;

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

    public ?string $currentVersion = null;

    public ?string $latestVersion = null;

    public ?string $installId = null;

    public ?string $releaseNotes = null;

    public function mount(): void
    {
        $this->currentVersion = (string) (config('laravel-crm.version') ?? '');

        $settings = app()->bound('laravel-crm.settings') ? app('laravel-crm.settings') : null;
        if ($settings) {
            $this->latestVersion = $settings->get('version_latest') ?: null;
            $this->installId = $settings->get('install_id') ?: null;
            $this->releaseNotes = $settings->get('version_latest_notes') ?: null;
        }
    }

    public function getIsUpToDateProperty(): bool
    {
        if (! $this->latestVersion || ! $this->currentVersion) {
            return true;
        }

        return version_compare($this->currentVersion, $this->latestVersion, '>=');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('checkForUpdates')
                ->label(__('laravel-crm-filament::labels.actions.check_for_updates'))
                ->icon('heroicon-o-arrow-path')
                // Queues an Artisan command — never expose it to a user who
                // cannot view updates in the first place.
                ->visible(fn (): bool => static::canAccess())
                ->action(function () {
                    abort_unless(static::canAccess(), 403);

                    Artisan::queue('laravelcrm:update');

                    Notification::make()
                        ->title('Update queued')
                        ->body('The laravelcrm:update command has been queued. Refresh shortly to see the new version.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
