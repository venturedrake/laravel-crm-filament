<?php

namespace VentureDrake\LaravelCrmFilament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Infolists\Components\TextEntry;
use Filament\Navigation\NavigationItem;
use Filament\Notifications\Notification;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Illuminate\Support\Facades\Route;
use VentureDrake\LaravelCrm\Support\Modules;
use VentureDrake\LaravelCrmFilament\Concerns\AuthorizesCrmSettingsPage;
use VentureDrake\LaravelCrmFilament\Support\XeroIntegration;

class Integrations extends Page implements HasForms
{
    use AuthorizesCrmSettingsPage;
    use InteractsWithForms;

    protected static string $crmPermission = 'view crm settings';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static string | \UnitEnum | null $navigationGroup = 'Settings';

    protected static ?string $title = 'Integrations';

    protected static ?string $slug = 'integrations';

    protected static ?int $navigationSort = 110;

    protected string $view = 'laravel-crm-filament::integrations';

    public array $data = [];

    /**
     * Returns the shared sub-navigation tabs for the Integrations cluster.
     * Xero (this Integrations page) is always present; ClickSend only appears
     * when the `sms-marketing` module is enabled.
     *
     * @return array<int, NavigationItem>
     */
    public static function integrationTabs(): array
    {
        $tabs = [
            NavigationItem::make(__('laravel-crm-filament::labels.sections.xero'))
                ->url(static::getUrl())
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.*.pages.integrations')),
        ];

        if (Modules::enabled('sms-marketing')) {
            $tabs[] = NavigationItem::make(__('laravel-crm-filament::labels.sections.clicksend'))
                ->url(ClickSendIntegration::getUrl())
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.*.pages.clicksend'));
        }

        return $tabs;
    }

    public function getSubNavigation(): array
    {
        return static::integrationTabs();
    }

    public static function getSubNavigationPosition(): SubNavigationPosition
    {
        return SubNavigationPosition::Top;
    }

    /**
     * The four Xero sync toggles base exposes, in base's own order — see
     * vendor/venturedrake/laravel-crm/src/Livewire/Settings/Integrations/Xero/XeroConnect.php
     * (contacts / products / quotes / invoices).
     */
    public const KEYS = [
        'xero_contacts' => 'Sync contacts to Xero',
        'xero_products' => 'Sync products to Xero',
        'xero_quotes' => 'Sync quotes to Xero',
        'xero_invoices' => 'Sync invoices to Xero',
    ];

    /**
     * ^1.1.3 rather than a bare require: core 2.4.2–2.4.4 still pin the
     * package to exactly 1.1.3, and this constraint satisfies that pin too.
     */
    public const XERO_INSTALL_COMMAND = 'composer require dcblogdev/laravel-xero:^1.1.3';

    public function mount(): void
    {
        $settings = app('laravel-crm.settings');
        foreach (array_keys(static::KEYS) as $key) {
            $this->data[$key] = (bool) $settings->get($key);
        }
        $this->form->fill($this->data);
    }

    public function form(Schema $schema): Schema
    {
        $installed = XeroIntegration::installed();
        $connected = $this->xeroIsConnected();

        return $schema
            ->statePath('data')
            ->components([
                Section::make('Xero')->heading(__('laravel-crm-filament::labels.sections.xero'))
                    ->description(__('laravel-crm-filament::labels.integrations.xero_description'))
                    ->schema([
                        Toggle::make('xero_contacts')->label(static::KEYS['xero_contacts']),
                        Toggle::make('xero_products')->label(static::KEYS['xero_products']),
                        Toggle::make('xero_quotes')->label(static::KEYS['xero_quotes']),
                        Toggle::make('xero_invoices')->label(static::KEYS['xero_invoices']),
                    ])
                    ->visible($connected),
                Section::make('XeroConnect')->heading(__('laravel-crm-filament::labels.sections.xero'))
                    ->description(__('laravel-crm-filament::labels.integrations.xero_description'))
                    ->schema([
                        Actions::make([
                            Action::make('connectXeroCta')
                                ->label(__('laravel-crm-filament::labels.actions.connect_xero'))
                                ->outlined()
                                // Connecting rewrites the org's integration
                                // state, so it belongs behind the same gate as
                                // the settings form, not merely `view`.
                                ->visible(fn (): bool => static::canEditCrmSettings())
                                // Core only registers its routes when
                                // laravel-crm.user_interface is on, and the
                                // headless install leaves it off.
                                ->hidden(fn (): bool => ! Route::has('laravel-crm.integrations.xero.connect'))
                                ->url(fn () => route('laravel-crm.integrations.xero.connect')),
                        ])->fullWidth(),
                    ])
                    ->visible($installed && ! $connected),
                // Core 2.4.5+ no longer installs dcblogdev/laravel-xero. Its
                // connect route then bounces to core's own settings screen, so
                // say what is missing here rather than offer a dead button.
                Section::make('XeroNotInstalled')->heading(__('laravel-crm-filament::labels.sections.xero'))
                    ->description(__('laravel-crm-filament::labels.integrations.xero_description'))
                    ->icon('heroicon-o-exclamation-triangle')
                    ->iconColor('warning')
                    ->schema([
                        TextEntry::make('xeroNotInstalled')
                            ->hiddenLabel()
                            ->state(__('laravel-crm-filament::labels.integrations.xero_not_installed')),
                        TextEntry::make('xeroInstallCommand')
                            ->hiddenLabel()
                            ->state(self::XERO_INSTALL_COMMAND)
                            ->fontFamily(FontFamily::Mono)
                            ->copyable(),
                    ])
                    ->visible(! $installed),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('disconnectXero')
                ->label(__('laravel-crm-filament::labels.actions.disconnect_xero'))
                ->icon('heroicon-o-link-slash')
                ->color('danger')
                ->requiresConfirmation()
                ->url(fn () => route('laravel-crm.integrations.xero.disconnect'))
                // Disconnecting tears down the org's Xero connection — a write,
                // so it needs `edit crm settings`, not just `view`.
                ->visible(fn () => $this->xeroIsConnected() && static::canEditCrmSettings())
                ->hidden(fn (): bool => ! Route::has('laravel-crm.integrations.xero.disconnect')),
        ];
    }

    public function xeroIsConnectedForView(): bool
    {
        return $this->xeroIsConnected();
    }

    protected function xeroIsConnected(): bool
    {
        return XeroIntegration::connected();
    }

    protected function getFormActions(): array
    {
        if (! $this->xeroIsConnected() || ! static::canEditCrmSettings()) {
            return [];
        }

        return [
            Action::make('save')->label(__('laravel-crm-filament::labels.actions.save_sync_settings'))->submit('save'),
        ];
    }

    public function save(): void
    {
        $this->authorizeCrmSettingsEdit();

        $data = $this->form->getState();
        $settings = app('laravel-crm.settings');

        foreach (static::KEYS as $key => $label) {
            $settings->set($key, $data[$key] ? '1' : '0', $label);
        }

        if (method_exists($settings, 'forgetCache')) {
            $settings->forgetCache();
        }

        Notification::make()->title('Sync settings saved')->success()->send();
    }
}
