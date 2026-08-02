<?php

namespace VentureDrake\LaravelCrmFilament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use VentureDrake\LaravelCrm\Services\ClickSendService;
use VentureDrake\LaravelCrmFilament\Concerns\AuthorizesCrmSettingsPage;

/**
 * Dedicated ClickSend SMS integration page. Persists the three core
 * credentials (`clicksend_username`, `clicksend_api_key`,
 * `clicksend_default_from`) via SettingService so the values are
 * shared with the existing Livewire UI, and exposes a "Send test SMS"
 * inline action (only visible once credentials are configured) that
 * round-trips through ClickSendService::sendSms().
 */
class ClickSendIntegration extends Page implements HasForms
{
    use AuthorizesCrmSettingsPage;
    use InteractsWithForms;

    protected static string $crmPermission = 'view crm settings';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-chat-bubble-bottom-center-text';

    protected static string | \UnitEnum | null $navigationGroup = 'Settings';

    protected static ?string $title = 'ClickSend';

    protected static ?string $slug = 'clicksend';

    protected static ?int $navigationSort = 115;

    /**
     * Never a top-level nav entry — reached through the Integrations
     * sub-navigation tabs. Overrides (and is stricter than) the
     * `AuthorizesCrmSettingsPage` implementation, which would otherwise fall
     * back to the parent behaviour for permitted users.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected string $view = 'laravel-crm-filament::clicksend';

    public const KEYS = [
        'clicksend_username' => 'ClickSend username',
        'clicksend_api_key' => 'ClickSend API key',
        'clicksend_default_from' => 'Default sender ID',
    ];

    public array $data = [];

    public function getSubNavigation(): array
    {
        return Integrations::integrationTabs();
    }

    public static function getSubNavigationPosition(): SubNavigationPosition
    {
        return SubNavigationPosition::Top;
    }

    public function mount(): void
    {
        $settings = app('laravel-crm.settings');
        foreach (array_keys(static::KEYS) as $key) {
            $this->data[$key] = $settings->get($key);
        }
        $this->form->fill($this->data);
    }

    public function form(Schema $schema): Schema
    {
        $signupHtml = new HtmlString(
            '<div style="display: flex; align-items: center; gap: 0.75rem;'
            . ' background-color: #38bdf8; color: #0f172a;'
            . ' padding: 0.75rem 1rem; border-radius: 0.5rem; font-size: 0.875rem;">'
            . '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"'
            . ' stroke-width="2" style="width: 1.25rem; height: 1.25rem; flex-shrink: 0;">'
            . '<path stroke-linecap="round" stroke-linejoin="round"'
            . ' d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />'
            . '</svg>'
            . '<span>' . e(__('laravel-crm-filament::labels.integrations.clicksend_signup_prefix')) . ' '
            . '<a href="https://clicksend.com/?u=47224" target="_blank" rel="noopener"'
            . ' style="text-decoration: underline; color: inherit;">clicksend.com</a>'
            . '</span></div>'
        );

        return $schema
            ->statePath('data')
            ->components([
                Section::make('ClickSend')
                    ->heading(__('laravel-crm-filament::labels.sections.clicksend'))
                    ->description(__('laravel-crm-filament::labels.integrations.clicksend_description'))
                    ->schema([
                        Placeholder::make('clicksend_signup_banner')
                            ->hiddenLabel()
                            ->content($signupHtml),
                        TextInput::make('clicksend_username')
                            ->label(__('laravel-crm-filament::labels.fields.clicksend_username'))
                            ->maxLength(255),
                        TextInput::make('clicksend_api_key')
                            ->label(__('laravel-crm-filament::labels.fields.clicksend_api_key'))
                            ->password()
                            ->revealable()
                            ->maxLength(255),
                        TextInput::make('clicksend_default_from')
                            ->label(__('laravel-crm-filament::labels.fields.clicksend_default_from'))
                            ->helperText(__('laravel-crm-filament::labels.integrations.clicksend_sender_id_hint'))
                            ->maxLength(32),
                        Actions::make([
                            Action::make('sendTestSms')
                                ->label(__('laravel-crm-filament::labels.actions.send_test_sms'))
                                ->icon('heroicon-o-paper-airplane')
                                ->color('primary')
                                ->outlined()
                                ->schema([
                                    TextInput::make('to')
                                        ->label(__('laravel-crm-filament::labels.fields.phone_number'))
                                        ->required()
                                        ->helperText('Use E.164 format, e.g. +15551234567'),
                                    TextInput::make('body')
                                        ->label(__('laravel-crm-filament::labels.fields.message'))
                                        ->required()
                                        ->maxLength(160)
                                        ->default('Test SMS from laravel-crm-filament.'),
                                ])
                                ->action(function (array $data): void {
                                    $service = app(ClickSendService::class);
                                    $service->refresh();

                                    if (! $service->isConfigured()) {
                                        Notification::make()
                                            ->title('ClickSend not configured')
                                            ->body('Save your ClickSend credentials before sending a test SMS.')
                                            ->danger()
                                            ->send();

                                        return;
                                    }

                                    try {
                                        $result = $service->sendSms(
                                            (string) $data['to'],
                                            (string) $data['body'],
                                            $service->defaultFrom(),
                                            'filament-test-sms',
                                        );
                                    } catch (\Throwable $e) {
                                        Notification::make()
                                            ->title('Test SMS failed')
                                            ->body($e->getMessage())
                                            ->danger()
                                            ->send();

                                        return;
                                    }

                                    if ($result['ok'] ?? false) {
                                        Notification::make()
                                            ->title('Test SMS sent')
                                            ->body('Message ID: ' . ($result['message_id'] ?? 'n/a'))
                                            ->success()
                                            ->send();

                                        return;
                                    }

                                    Notification::make()
                                        ->title('Test SMS failed')
                                        ->body($result['error'] ?? 'ClickSend rejected the message.')
                                        ->danger()
                                        ->send();
                                }),
                        ])->visible(fn (): bool => $this->clickSendIsConfigured() && static::canEditCrmSettings()),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function clickSendIsConfigured(): bool
    {
        try {
            return app(ClickSendService::class)->isConfigured();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The blade view renders its own submit button (gated on
     * `canEditCrmSettings()`); this mirrors GeneralSettings / Integrations so
     * any Filament-rendered form action is gated on the same permission.
     *
     * @return array<int, Action>
     */
    protected function getFormActions(): array
    {
        if (! static::canEditCrmSettings()) {
            return [];
        }

        return [
            Action::make('save')
                ->label(__('laravel-crm-filament::labels.actions.save_changes'))
                ->submit('save'),
        ];
    }

    public function save(): void
    {
        $this->authorizeCrmSettingsEdit();

        $data = $this->form->getState();
        $settings = app('laravel-crm.settings');

        foreach (static::KEYS as $key => $label) {
            $settings->set($key, $data[$key] ?? null, $label);
        }

        if (method_exists($settings, 'forgetCache')) {
            $settings->forgetCache();
        }

        app(ClickSendService::class)->refresh();

        Notification::make()
            ->title('ClickSend settings saved')
            ->success()
            ->send();
    }
}
