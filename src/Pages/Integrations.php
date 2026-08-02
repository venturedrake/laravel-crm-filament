<?php

namespace VentureDrake\LaravelCrmFilament\Pages;

use BackedEnum;
use Dcblogdev\Xero\Facades\Xero;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Navigation\NavigationItem;
use Filament\Notifications\Notification;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use VentureDrake\LaravelCrmFilament\Concerns\AuthorizesCrmSettingsPage;

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

        if (in_array('sms-marketing', (array) config('laravel-crm.modules', []), true)) {
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

    public const KEYS = [
        'xero_contacts' => 'Sync contacts to Xero',
        'xero_products' => 'Sync products to Xero',
        'xero_invoices' => 'Sync invoices to Xero',
    ];

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
        $connected = $this->xeroIsConnected();

        return $schema
            ->statePath('data')
            ->components([
                Section::make('Xero')->heading(__('laravel-crm-filament::labels.sections.xero'))
                    ->description(__('laravel-crm-filament::labels.integrations.xero_description'))
                    ->schema([
                        Toggle::make('xero_contacts')->label(static::KEYS['xero_contacts']),
                        Toggle::make('xero_products')->label(static::KEYS['xero_products']),
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
                                ->url(fn () => route('laravel-crm.integrations.xero.connect')),
                        ])->fullWidth(),
                    ])
                    ->visible(! $connected),
            ]);
    }

    protected function xeroStatusLine(): string
    {
        try {
            if (class_exists(Xero::class) && Xero::isConnected()) {
                $tenant = method_exists(Xero::class, 'getTenantName')
                    ? Xero::getTenantName()
                    : 'Connected';

                return "Connected to Xero: {$tenant}";
            }
        } catch (\Throwable $e) {
            return 'Xero status unavailable';
        }

        return 'Not connected to Xero.';
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
                ->visible(fn () => $this->xeroIsConnected()),
        ];
    }

    public function xeroIsConnectedForView(): bool
    {
        return $this->xeroIsConnected();
    }

    protected function xeroIsConnected(): bool
    {
        try {
            return class_exists(Xero::class)
                && Xero::isConnected();
        } catch (\Throwable $e) {
            return false;
        }
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
