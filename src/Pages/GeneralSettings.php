<?php

namespace VentureDrake\LaravelCrmFilament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Ramsey\Uuid\Uuid;
use VentureDrake\LaravelCrm\Models\Address;
use VentureDrake\LaravelCrm\Models\AddressType;
use VentureDrake\LaravelCrm\Models\Email;
use VentureDrake\LaravelCrm\Models\Phone;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Concerns\AuthorizesCrmSettingsPage;

/**
 * General settings page backed by `SettingService` (`laravel-crm.settings`).
 *
 * Scalar fields persist via `SettingService::set`. Phones / emails / addresses
 * are polymorphic rows attached to the `Setting{name='team'}` anchor: read raw
 * in `mount()`, diff submitted state in `save()` calling create/update/delete
 * on the model directly. Pattern lifted from
 * vendor/venturedrake/laravel-crm/src/Livewire/Settings/SettingEdit.php::save().
 *
 * Phone/Email use the `type` ENUM column (work/home/mobile/fax/other and
 * work/home/other respectively) because no PhoneType/EmailType lookup model
 * ships in core CRM — verified against the migration stubs at
 * vendor/venturedrake/laravel-crm/database/migrations/create_laravel_crm_tables.php.stub
 * (per the Codebase Patterns warning). Address uses `address_type_id` (real FK
 * to crm_address_types) since the AddressType model exists.
 */
class GeneralSettings extends Page implements HasForms
{
    use AuthorizesCrmSettingsPage;
    use InteractsWithForms;

    protected static string $crmPermission = 'view crm settings';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-adjustments-vertical';

    protected static string | \UnitEnum | null $navigationGroup = 'Settings';

    protected static ?string $title = 'General';

    protected static ?string $slug = 'general';

    protected static ?int $navigationSort = 10;

    protected string $view = 'laravel-crm-filament::general-settings';

    /**
     * Scalar setting keys this page edits, with a friendly label for the form
     * field AND for the `SettingService::set($key, $value, $label)` write.
     *
     * The AC story header says "25 entries" but its explicit enumeration lists
     * 26 keys; the explicit list is authoritative.
     */
    public const KEYS = [
        // Branding
        'organization_name' => 'Organization name',
        'vat_number' => 'VAT number',
        'logo_file' => 'Logo file',
        // Localisation
        'language' => 'Language',
        'country' => 'Country',
        'currency' => 'Currency',
        'timezone' => 'Timezone',
        'date_format' => 'Date format',
        'time_format' => 'Time format',
        // Prefixes
        'lead_prefix' => 'Lead prefix',
        'deal_prefix' => 'Deal prefix',
        'quote_prefix' => 'Quote prefix',
        'order_prefix' => 'Order prefix',
        'invoice_prefix' => 'Invoice prefix',
        'delivery_prefix' => 'Delivery prefix',
        'purchase_order_prefix' => 'Purchase order prefix',
        // Document defaults
        'quote_terms' => 'Quote terms',
        'invoice_contact_details' => 'Invoice contact details',
        'invoice_terms' => 'Invoice terms',
        'invoice_payment_instructions' => 'Invoice payment instructions',
        'purchase_order_terms' => 'Purchase order terms',
        'purchase_order_delivery_instructions' => 'Purchase order delivery instructions',
        // Tax
        'tax_name' => 'Tax name',
        'tax_rate' => 'Default tax rate',
        // Behaviour
        'show_related_activity' => 'Show related activity',
        'dynamic_products' => 'Dynamic products',
    ];

    public array $data = [];

    public function mount(): void
    {
        $settings = app('laravel-crm.settings');
        foreach (array_keys(static::KEYS) as $key) {
            $this->data[$key] = $settings->get($key);
        }

        $anchor = $this->resolveAnchor();
        $this->data['phones'] = $anchor->phones->map(fn (Phone $p) => [
            'id' => $p->id,
            'type' => $p->type,
            'number' => $p->number,
        ])->values()->all();
        $this->data['emails'] = $anchor->emails->map(fn (Email $e) => [
            'id' => $e->id,
            'type' => $e->type,
            'address' => $e->address,
        ])->values()->all();
        $this->data['addresses'] = $anchor->addresses->map(fn (Address $a) => [
            'id' => $a->id,
            'address_type_id' => $a->address_type_id,
            'line1' => $a->line1,
            'line2' => $a->line2,
            'line3' => $a->line3,
            'city' => $a->city,
            'state' => $a->state,
            'code' => $a->code,
            'country' => $a->country,
        ])->values()->all();

        $this->form->fill($this->data);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('branding')
                    ->heading(__('laravel-crm-filament::labels.sections.branding'))
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('organization_name')->label(static::KEYS['organization_name'])->maxLength(255),
                            TextInput::make('vat_number')->label(static::KEYS['vat_number'])->maxLength(255),
                        ]),
                        TextInput::make('logo_file')->label(static::KEYS['logo_file'])->maxLength(255),
                    ]),

                Section::make('localisation')
                    ->heading(__('laravel-crm-filament::labels.sections.localisation'))
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('language')
                                ->label(static::KEYS['language'])
                                ->options(['english' => 'English'])
                                ->searchable(),
                            Select::make('country')
                                ->label(static::KEYS['country'])
                                ->options(static::countryOptions())
                                ->searchable(),
                            Select::make('currency')
                                ->label(static::KEYS['currency'])
                                ->options(static::currencyOptions())
                                ->searchable(),
                            Select::make('timezone')
                                ->label(static::KEYS['timezone'])
                                ->options(static::timezoneOptions())
                                ->searchable(),
                            Select::make('date_format')
                                ->label(static::KEYS['date_format'])
                                ->options(static::dateFormatOptions()),
                            Select::make('time_format')
                                ->label(static::KEYS['time_format'])
                                ->options(static::timeFormatOptions()),
                        ]),
                    ]),

                Section::make('prefixes')
                    ->heading(__('laravel-crm-filament::labels.sections.prefixes'))
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('lead_prefix')->label(static::KEYS['lead_prefix'])->maxLength(10),
                            TextInput::make('deal_prefix')->label(static::KEYS['deal_prefix'])->maxLength(10),
                            TextInput::make('quote_prefix')->label(static::KEYS['quote_prefix'])->maxLength(10),
                            TextInput::make('order_prefix')->label(static::KEYS['order_prefix'])->maxLength(10),
                            TextInput::make('invoice_prefix')->label(static::KEYS['invoice_prefix'])->maxLength(10),
                            TextInput::make('delivery_prefix')->label(static::KEYS['delivery_prefix'])->maxLength(10),
                            TextInput::make('purchase_order_prefix')->label(static::KEYS['purchase_order_prefix'])->maxLength(10),
                        ]),
                    ]),

                Section::make('document_defaults')
                    ->heading(__('laravel-crm-filament::labels.sections.document_defaults'))
                    ->schema([
                        Textarea::make('quote_terms')->label(static::KEYS['quote_terms'])->rows(3),
                        Textarea::make('invoice_contact_details')->label(static::KEYS['invoice_contact_details'])->rows(3),
                        Textarea::make('invoice_terms')->label(static::KEYS['invoice_terms'])->rows(3),
                        Textarea::make('invoice_payment_instructions')->label(static::KEYS['invoice_payment_instructions'])->rows(3),
                        Textarea::make('purchase_order_terms')->label(static::KEYS['purchase_order_terms'])->rows(3),
                        Textarea::make('purchase_order_delivery_instructions')->label(static::KEYS['purchase_order_delivery_instructions'])->rows(3),
                    ]),

                Section::make('tax')
                    ->heading(__('laravel-crm-filament::labels.sections.tax'))
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('tax_name')->label(static::KEYS['tax_name'])->maxLength(255),
                            TextInput::make('tax_rate')->label(static::KEYS['tax_rate'])->numeric()->suffix('%'),
                        ]),
                    ]),

                Section::make('behaviour')
                    ->heading(__('laravel-crm-filament::labels.sections.behaviour'))
                    ->schema([
                        Toggle::make('show_related_activity')->label(static::KEYS['show_related_activity']),
                        Toggle::make('dynamic_products')->label(static::KEYS['dynamic_products']),
                    ]),

                Section::make('phones')
                    ->heading(__('laravel-crm-filament::labels.contact.phone_numbers'))
                    ->schema([
                        Repeater::make('phones')
                            ->hiddenLabel()
                            ->schema([
                                Hidden::make('id'),
                                Select::make('type')
                                    ->label(__('laravel-crm-filament::labels.fields.type'))
                                    ->options(static::phoneTypeOptions())
                                    ->default('work'),
                                TextInput::make('number')
                                    ->label(__('laravel-crm-filament::labels.contact.number'))
                                    ->required()
                                    ->tel()
                                    ->maxLength(50),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->reorderable(false)

                            ->columnSpanFull(),
                    ]),

                Section::make('emails')
                    ->heading(__('laravel-crm-filament::labels.contact.email_addresses'))
                    ->schema([
                        Repeater::make('emails')
                            ->hiddenLabel()
                            ->schema([
                                Hidden::make('id'),
                                Select::make('type')
                                    ->label(__('laravel-crm-filament::labels.fields.type'))
                                    ->options(static::emailTypeOptions())
                                    ->default('work'),
                                TextInput::make('address')
                                    ->label(__('laravel-crm-filament::labels.contact.email'))
                                    ->required()
                                    ->email()
                                    ->maxLength(255),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->reorderable(false)

                            ->columnSpanFull(),
                    ]),

                Section::make('addresses')
                    ->heading(__('laravel-crm-filament::labels.contact.addresses'))
                    ->schema([
                        Repeater::make('addresses')
                            ->hiddenLabel()
                            ->schema([
                                Hidden::make('id'),
                                Select::make('address_type_id')
                                    ->label(__('laravel-crm-filament::labels.fields.type'))
                                    ->options(fn () => AddressType::query()->orderBy('name')->pluck('name', 'id')->toArray()),
                                TextInput::make('line1')->label(__('laravel-crm-filament::labels.contact.line1'))->maxLength(255),
                                TextInput::make('line2')->label(__('laravel-crm-filament::labels.contact.line2'))->maxLength(255),
                                TextInput::make('line3')->label(__('laravel-crm-filament::labels.contact.line3'))->maxLength(255),
                                TextInput::make('city')->label(__('laravel-crm-filament::labels.contact.city'))->maxLength(100),
                                TextInput::make('state')->label(__('laravel-crm-filament::labels.contact.state'))->maxLength(100),
                                TextInput::make('code')->label(__('laravel-crm-filament::labels.contact.post_code'))->maxLength(20),
                                TextInput::make('country')->label(__('laravel-crm-filament::labels.contact.country'))->maxLength(100),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->reorderable(false)

                            ->columnSpanFull(),
                    ]),
            ]);
    }

    protected function getFormActions(): array
    {
        if (! static::canEditCrmSettings()) {
            return [];
        }

        return [
            Action::make('save')
                ->label(__('laravel-crm-filament::labels.actions.save'))
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

        $anchor = $this->resolveAnchor();
        $this->syncPhones($anchor, $data['phones'] ?? []);
        $this->syncEmails($anchor, $data['emails'] ?? []);
        $this->syncAddresses($anchor, $data['addresses'] ?? []);

        if (method_exists($settings, 'forgetCache')) {
            $settings->forgetCache();
        }

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }

    /**
     * Resolve (or lazily create) the Setting row that anchors polymorphic
     * phones/emails/addresses for the current team. Core CRM's Settings
     * middleware seeds this row on every request, but the Filament page may
     * be exercised before that middleware runs (e.g. in tests), so a
     * firstOrCreate guard belongs here too.
     */
    protected function resolveAnchor(): Setting
    {
        $anchor = Setting::firstOrCreate(
            ['name' => 'team'],
            ['value' => 'related']
        );

        // Force-load the morphMany collections so mount() and save() see the
        // current state in one query batch rather than three N+1 reads.
        $anchor->load(['phones', 'emails', 'addresses']);

        return $anchor;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function syncPhones(Setting $anchor, array $rows): void
    {
        $keptIds = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $attrs = [
                'number' => $row['number'] ?? null,
                'type' => $row['type'] ?? null,
            ];

            if ($id && $phone = Phone::find($id)) {
                $phone->update($attrs);
                $keptIds[] = (int) $phone->id;
            } elseif (! empty($attrs['number'])) {
                $phone = $anchor->phones()->create(array_merge($attrs, [
                    'external_id' => Uuid::uuid4()->toString(),
                ]));
                $keptIds[] = (int) $phone->id;
            }
        }

        foreach ($anchor->phones()->get() as $existing) {
            if (! in_array((int) $existing->id, $keptIds, true)) {
                $existing->delete();
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function syncEmails(Setting $anchor, array $rows): void
    {
        $keptIds = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $attrs = [
                'address' => $row['address'] ?? null,
                'type' => $row['type'] ?? null,
            ];

            if ($id && $email = Email::find($id)) {
                $email->update($attrs);
                $keptIds[] = (int) $email->id;
            } elseif (! empty($attrs['address'])) {
                $email = $anchor->emails()->create(array_merge($attrs, [
                    'external_id' => Uuid::uuid4()->toString(),
                ]));
                $keptIds[] = (int) $email->id;
            }
        }

        foreach ($anchor->emails()->get() as $existing) {
            if (! in_array((int) $existing->id, $keptIds, true)) {
                $existing->delete();
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function syncAddresses(Setting $anchor, array $rows): void
    {
        $keptIds = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $attrs = [
                'address_type_id' => $row['address_type_id'] ?? null,
                'line1' => $row['line1'] ?? null,
                'line2' => $row['line2'] ?? null,
                'line3' => $row['line3'] ?? null,
                'city' => $row['city'] ?? null,
                'state' => $row['state'] ?? null,
                'code' => $row['code'] ?? null,
                'country' => $row['country'] ?? null,
            ];

            $hasContent = collect($attrs)->contains(fn ($v) => $v !== null && $v !== '');

            if ($id && $address = Address::find($id)) {
                $address->update($attrs);
                $keptIds[] = (int) $address->id;
            } elseif ($hasContent) {
                $address = $anchor->addresses()->create(array_merge($attrs, [
                    'external_id' => Uuid::uuid4()->toString(),
                ]));
                $keptIds[] = (int) $address->id;
            }
        }

        foreach ($anchor->addresses()->get() as $existing) {
            if (! in_array((int) $existing->id, $keptIds, true)) {
                $existing->delete();
            }
        }
    }

    protected static function countryOptions(): array
    {
        $options = [];
        if (function_exists('VentureDrake\\LaravelCrm\\Http\\Helpers\\SelectOptions\\countries')) {
            foreach (\VentureDrake\LaravelCrm\Http\Helpers\SelectOptions\countries() as $entry) {
                $name = $entry['name'] ?? null;
                if ($name) {
                    $options[$name] = $name;
                }
            }
        }

        return $options;
    }

    protected static function currencyOptions(): array
    {
        if (function_exists('VentureDrake\\LaravelCrm\\Http\\Helpers\\SelectOptions\\currencies')) {
            return \VentureDrake\LaravelCrm\Http\Helpers\SelectOptions\currencies();
        }

        return [];
    }

    protected static function timezoneOptions(): array
    {
        if (function_exists('VentureDrake\\LaravelCrm\\Http\\Helpers\\SelectOptions\\timezones')) {
            $options = \VentureDrake\LaravelCrm\Http\Helpers\SelectOptions\timezones();
            unset($options['']);

            return $options;
        }

        return [];
    }

    protected static function dateFormatOptions(): array
    {
        if (function_exists('VentureDrake\\LaravelCrm\\Http\\Helpers\\SelectOptions\\dateFormats')) {
            return \VentureDrake\LaravelCrm\Http\Helpers\SelectOptions\dateFormats();
        }

        return [];
    }

    protected static function timeFormatOptions(): array
    {
        if (function_exists('VentureDrake\\LaravelCrm\\Http\\Helpers\\SelectOptions\\timeFormats')) {
            return \VentureDrake\LaravelCrm\Http\Helpers\SelectOptions\timeFormats();
        }

        return [];
    }

    protected static function phoneTypeOptions(): array
    {
        if (function_exists('VentureDrake\\LaravelCrm\\Http\\Helpers\\SelectOptions\\phoneTypes')) {
            $opts = \VentureDrake\LaravelCrm\Http\Helpers\SelectOptions\phoneTypes();
            if (! empty($opts)) {
                return $opts;
            }
        }

        return [
            'work' => 'Work',
            'home' => 'Home',
            'mobile' => 'Mobile',
            'fax' => 'Fax',
            'other' => 'Other',
        ];
    }

    protected static function emailTypeOptions(): array
    {
        if (function_exists('VentureDrake\\LaravelCrm\\Http\\Helpers\\SelectOptions\\emailTypes')) {
            $opts = \VentureDrake\LaravelCrm\Http\Helpers\SelectOptions\emailTypes();
            if (! empty($opts)) {
                return $opts;
            }
        }

        return [
            'work' => 'Work',
            'home' => 'Home',
            'other' => 'Other',
        ];
    }
}
