<?php

namespace VentureDrake\LaravelCrmFilament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
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
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Ramsey\Uuid\Uuid;
use VentureDrake\LaravelCrm\Models\Address;
use VentureDrake\LaravelCrm\Models\AddressType;
use VentureDrake\LaravelCrm\Models\Email;
use VentureDrake\LaravelCrm\Models\Phone;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrm\Services\SettingService;
use VentureDrake\LaravelCrm\Support\Modules;
use VentureDrake\LaravelCrm\Support\PdfContactDetails;
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
     *
     * `pdf_contact_details` post-dates that enumeration entirely: core 2.4.1
     * added it as the shared "From" block behind
     * {@see PdfContactDetails::for()}, and without a field here there is no
     * screen in the panel that can fill it.
     */
    public const KEYS = [
        // Branding
        'organization_name' => 'Organization name',
        'vat_number' => 'VAT number',
        'logo_file' => 'Logo file',
        'primary_color' => 'Primary color',
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
        PdfContactDetails::SHARED_KEY => 'Document contact details',
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

    /**
     * Companion setting written alongside `logo_file` by the logo uploader.
     * Not a KEYS entry — it has no form field of its own; it mirrors base
     * (SettingEdit::save()), which stores the original client filename here.
     */
    public const LOGO_FILE_NAME_KEY = 'logo_file_name';

    /**
     * Directory the logo is uploaded into on the `public` disk.
     *
     * Base picks `laravel-crm/{team}` when `config('laravel-crm.teams')` is on
     * and the user has a current team, else plain `laravel-crm`. The Filament
     * panel this plugin registers is NOT multi-tenant (no `->tenant()` call
     * anywhere in the plugin — verified against LaravelCrmPlugin::register()),
     * so the non-tenant path is the correct one here.
     */
    public const LOGO_DIRECTORY = 'laravel-crm';

    public array $data = [];

    public function mount(): void
    {
        $settings = app('laravel-crm.settings');
        foreach (array_keys(static::KEYS) as $key) {
            $this->data[$key] = $settings->get($key);
        }

        // The uploader is hydrated from the already-stored logo path so the
        // current logo previews in the field; `saveUploadedFiles()` passes
        // plain string paths straight through on the way back out.
        $this->data['logo_upload'] = $this->data['logo_file'] ?? null;

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

    /**
     * The entity tabs, in the order records flow through the CRM: the
     * `laravel-crm.modules` slug that hides each one when its module is off,
     * and the ID prefix key it owns.
     *
     * Mirrors core 2.4.1's split of the same page. The order is deliberate and
     * is not alphabetical. Module slug and prefix live together so a new entity
     * tab is one entry rather than three parallel lists to keep in step.
     *
     * @var array<string, array{module: string, prefix: string}>
     */
    public const ENTITY_TABS = [
        'leads' => ['module' => 'leads', 'prefix' => 'lead_prefix'],
        'deals' => ['module' => 'deals', 'prefix' => 'deal_prefix'],
        'quotes' => ['module' => 'quotes', 'prefix' => 'quote_prefix'],
        'orders' => ['module' => 'orders', 'prefix' => 'order_prefix'],
        'invoices' => ['module' => 'invoices', 'prefix' => 'invoice_prefix'],
        'deliveries' => ['module' => 'deliveries', 'prefix' => 'delivery_prefix'],
        'purchase_orders' => ['module' => 'purchase-orders', 'prefix' => 'purchase_order_prefix'],
    ];

    /**
     * The page's form.
     *
     * Split across tabs at core 2.4.1's prompting: ~28 unrelated controls in one
     * flat column meant finding "invoice terms" by scrolling past everything
     * else, and the list only grows as document settings accumulate. General
     * keeps what belongs to no single entity — identity, localisation, tax, the
     * shared contact block, the two account-wide toggles and the contact panels
     * — and each entity tab holds that entity's ID prefix beside its own terms.
     *
     * It is still one schema over one `$data` state path and one atomic save,
     * so `save()` writes every rendered key whichever tab happens to be showing:
     * Filament renders the non-active tabs' fields into the DOM rather than
     * dropping them, so their state round-trips like any other field, and a page
     * that saved only what was *visible* would silently blank the rest.
     *
     * Module gating is the one case where that is not enough, and it is why
     * `save()` skips keys absent from `getState()` rather than defaulting them
     * to null. A tab whose module is off is not built at all — not built and
     * hidden, not built and rendered — so its fields contribute no state, and
     * writing null for them would destroy settings core still reads on a
     * configuration this page deliberately supports. See {@see self::save()}.
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Tabs::make('settings')
                    ->persistTabInQueryString()
                    ->columnSpanFull()
                    ->tabs([
                        Tabs\Tab::make('general')
                            ->label(__('laravel-crm-filament::labels.sections.general'))
                            ->schema($this->generalTabSchema()),
                        ...$this->entityTabs(),
                    ]),
            ]);
    }

    /**
     * One tab per entity, each hidden when its module is off.
     *
     * @return array<int, Tabs\Tab>
     */
    protected function entityTabs(): array
    {
        $tabs = [];

        foreach (static::ENTITY_TABS as $tab => $config) {
            if (! Modules::enabled($config['module'])) {
                continue;
            }

            $tabs[] = Tabs\Tab::make($tab)
                ->label(__('laravel-crm-filament::labels.sections.' . $tab))
                ->schema($this->entityTabSchema($tab));
        }

        return $tabs;
    }

    /**
     * An entity tab: its ID prefix, then whatever document defaults it owns.
     *
     * @return array<int, mixed>
     */
    protected function entityTabSchema(string $tab): array
    {
        $prefixKey = static::ENTITY_TABS[$tab]['prefix'];

        $components = [
            TextInput::make($prefixKey)->label(static::KEYS[$prefixKey])->maxLength(10),
        ];

        return [...$components, ...match ($tab) {
            'quotes' => [
                Textarea::make('quote_terms')->label(static::KEYS['quote_terms'])->rows(3),
            ],
            'invoices' => [
                Textarea::make('invoice_contact_details')
                    ->label(static::KEYS['invoice_contact_details'])
                    ->helperText('Overrides the shared contact details on the General tab, on invoice PDFs only.')
                    ->rows(3),
                Textarea::make('invoice_terms')->label(static::KEYS['invoice_terms'])->rows(3),
                Textarea::make('invoice_payment_instructions')->label(static::KEYS['invoice_payment_instructions'])->rows(3),
            ],
            'purchase_orders' => [
                Textarea::make('purchase_order_terms')->label(static::KEYS['purchase_order_terms'])->rows(3),
                Textarea::make('purchase_order_delivery_instructions')->label(static::KEYS['purchase_order_delivery_instructions'])->rows(3),
            ],
            default => [],
        }];
    }

    /**
     * The General tab: everything that belongs to no single entity.
     *
     * @return array<int, mixed>
     */
    protected function generalTabSchema(): array
    {
        return [
            Section::make('branding')
                ->heading(__('laravel-crm-filament::labels.sections.branding'))
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('organization_name')->label(static::KEYS['organization_name'])->maxLength(255),
                        TextInput::make('vat_number')->label(static::KEYS['vat_number'])->maxLength(255),
                    ]),
                    FileUpload::make('logo_upload')
                        ->label(static::KEYS['logo_file'])
                        ->image()
                        // ->image() alone maps to `mimetypes:image/*`, which
                        // accepts image/svg+xml. An SVG is script-bearing
                        // markup served same-origin off the public disk, so
                        // pin the raster formats a brand logo actually needs.
                        ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/gif', 'image/webp'])
                        ->maxSize(1024)
                        ->disk('public')
                        ->directory(static::LOGO_DIRECTORY)
                        ->visibility('public')
                        // Base stores the logo under its original client
                        // filename, so keep the same so a logo uploaded
                        // from either UI resolves to the same asset path.
                        ->preserveFilenames()
                        // There had been no way to remove a logo at all,
                        // only to replace it — and clearing the field
                        // cannot mean "delete", because hydrateFiles()
                        // silently blanks a path it cannot see on the disk
                        // (see saveLogo()). Deletion has to be an explicit
                        // intent, so it is an explicit button.
                        ->hintAction(
                            Action::make('deleteLogo')
                                ->label(__('laravel-crm-filament::labels.actions.remove_logo'))
                                ->icon('heroicon-m-trash')
                                ->color('danger')
                                ->requiresConfirmation()
                                ->modalDescription(__('laravel-crm-filament::labels.actions.remove_logo_confirm'))
                                ->visible(fn (): bool => static::canEditCrmSettings() && $this->hasLogo())
                                ->action(fn () => $this->deleteLogo()),
                        ),
                    ColorPicker::make('primary_color')->label(static::KEYS['primary_color']),
                ]),

            Section::make('localisation')
                ->heading(__('laravel-crm-filament::labels.sections.localisation'))
                ->schema([
                    Grid::make(2)->schema([
                        Select::make('language')
                            ->label(static::KEYS['language'])
                            ->options(static::languageOptions())
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

            Section::make('document_defaults')
                ->heading(__('laravel-crm-filament::labels.sections.document_defaults'))
                ->schema([
                    // The shared block only — the per-entity overrides moved
                    // to their own tabs, which is the whole point of the
                    // split. This one is account-wide, so it stays here.
                    Textarea::make(PdfContactDetails::SHARED_KEY)
                        ->label(static::KEYS[PdfContactDetails::SHARED_KEY])
                        ->helperText('Shown in the "from" block on quote, order, delivery and invoice PDFs, unless the document type overrides it on its own tab; the classic template shows this block on invoices only.')
                        ->rows(3),
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
        ];
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
            if ($key === 'logo_file') {
                // Written by the uploader below, from the `logo_upload` field.
                continue;
            }

            // Only the keys the schema actually rendered. An entity tab whose
            // module is off is not *built*, so getState() carries no entry for
            // the fields on it — and `?? null` would then write a null over
            // every one of them, blanking a prefix or a terms block that core's
            // own CRM still reads and that the panel gave no way to restore.
            //
            // `array_key_exists`, not `isset`/`??`: a field that was rendered
            // and cleared comes back present-and-null, and that is a deliberate
            // clear which still has to persist. Absent and null are different
            // answers here, which is the whole distinction the tab split
            // introduced.
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $settings->set($key, $data[$key], $label);
        }

        $this->saveLogo($settings, $data['logo_upload'] ?? null);

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
     * Persist the uploaded logo, mirroring base's
     * SettingEdit::save() (vendor/venturedrake/laravel-crm/src/Livewire/Settings/SettingEdit.php:322-333):
     * `logo_file` holds the disk-relative path, `logo_file_name` the filename.
     *
     * Filament has already moved the file onto the `public` disk by the time
     * `getState()` returns (BaseFileUpload::saveUploadedFiles() runs on
     * dehydration), so all that's left is to record the path.
     *
     * A blank state is deliberately a no-op rather than a clear: the field
     * hydrates through BaseFileUpload::hydrateFiles(), which silently drops
     * paths that don't exist on the disk, so an unrelated save would otherwise
     * wipe a logo_file written by base into a different location. Base is
     * likewise upload-only here.
     *
     * @param  mixed  $upload  disk-relative path produced by the FileUpload field
     */
    protected function saveLogo(SettingService $settings, mixed $upload): void
    {
        if (! is_string($upload) || blank($upload)) {
            return;
        }

        $settings->set('logo_file', $upload, static::KEYS['logo_file']);
        $settings->set(static::LOGO_FILE_NAME_KEY, basename($upload), 'Logo file name');
    }

    /**
     * Whether there is a logo on screen to remove — a saved one, or one just
     * uploaded and not yet saved.
     */
    public function hasLogo(): bool
    {
        // The raw state, not a resolved path: a file the user has just picked
        // is a TemporaryUploadedFile rather than a path, and it is still very
        // much a logo on screen to remove.
        return filled($this->data['logo_upload'] ?? null)
            || filled($this->data['logo_file'] ?? null);
    }

    /**
     * The disk-relative path out of the uploader's state, whichever of its two
     * shapes it is currently in.
     *
     * A FileUpload does not hold a path. `BaseFileUpload::hydrateFiles()` turns
     * the string this page seeds in `mount()` into a uuid-keyed map
     * (`['9f1c…' => 'laravel-crm/brand.png']`), which is the shape it keeps for
     * the whole life of the mounted component and the shape Livewire round-trips
     * from the browser; only dehydration on save flattens it back to a string.
     *
     * So both shapes are real, and comparing the raw state against `logo_file`
     * compares an array with a string — which is never equal, however identical
     * the paths inside. That is what made {@see self::deleteLogo()} read every
     * saved logo as an unsaved upload and refuse to delete it.
     *
     * Null for a file the user has just picked, and that is the answer rather
     * than a gap: until `saveUploadedFiles()` runs on dehydration the entry is a
     * `TemporaryUploadedFile`, which has no stored path and by definition is not
     * the saved one. Callers must therefore ask "is anything in the uploader?"
     * of the *raw* state and only compare paths through here — asking both
     * questions of this method reads a pending upload as an empty field and
     * deletes the logo it was supposed to protect.
     */
    protected static function logoUploadPath(mixed $state): ?string
    {
        if (is_array($state)) {
            // Single-file uploader, so there is at most one entry; the uuid key
            // is Filament's and carries no meaning here.
            $state = reset($state);
        }

        return (is_string($state) && filled($state)) ? $state : null;
    }

    /**
     * The uploader's state for a stored path, in the hydrated shape the mounted
     * component uses. Null clears the field.
     *
     * @return array<string, string>
     */
    protected static function logoUploadState(?string $path): array
    {
        return filled($path) ? [Uuid::uuid4()->toString() => $path] : [];
    }

    /**
     * Remove whichever logo is on screen.
     *
     * A *pending* upload discards back to the saved logo rather than deleting
     * it: the artwork still printing on every PDF is not what the user was
     * pointing at when they clicked remove on a file they had just picked.
     * Only when there is nothing pending does this clear the stored setting.
     *
     * The stored keys are blanked rather than deleted, matching how the rest of
     * this page clears a field — core's `SettingService::set()` is an
     * unconditional `updateOrCreate`, and an empty row is a value the readers
     * already handle (`PdfLogo::fromSettings()` resolves it to null, so the
     * templates fall back to the organisation name as text).
     *
     * The file itself is deliberately left on the `public` disk. It may be a
     * filename base wrote, or one shared with another install pointed at the
     * same storage, and unlinking bytes on the strength of a settings row is
     * not recoverable; clearing the reference is.
     */
    public function deleteLogo(): void
    {
        $this->authorizeCrmSettingsEdit();

        $saved = $this->data['logo_file'] ?? null;
        $pending = $this->data['logo_upload'] ?? null;

        // Two different questions, deliberately asked of two different things.
        //
        // "Is there anything in the uploader?" is asked of the raw state,
        // because a file the user has just picked is a TemporaryUploadedFile
        // and no path resolves out of it.
        //
        // "Is it the saved logo?" is asked of the resolved path, because the
        // uploader holds a uuid-keyed map on a mounted page and a bare string
        // only after dehydration — so comparing raw state against `logo_file`
        // compares an array with a string and is true however identical the
        // paths inside. That read every saved logo as an unsaved upload and
        // made this method refuse to delete anything.
        //
        // Asking both of the resolved path is the opposite failure: a pending
        // upload resolves to null, reads as an empty field, and falls through
        // to the branch that deletes the artwork this one exists to protect.
        //
        // The state is written directly rather than through `$this->form->fill()`:
        // the schema's statePath *is* `data`, so the component re-reads it, and
        // fill() would re-hydrate every other field on the page as a side
        // effect of removing a logo.
        if (filled($pending) && static::logoUploadPath($pending) !== $saved) {
            // Put the saved logo back in the shape the live component reads,
            // not the bare string this page only ever sees pre-hydration.
            $this->data['logo_upload'] = static::logoUploadState($saved);

            Notification::make()
                ->title(__('laravel-crm-filament::labels.actions.remove_logo_discarded'))
                ->success()
                ->send();

            return;
        }

        $settings = app('laravel-crm.settings');

        $settings->set('logo_file', null, static::KEYS['logo_file']);
        $settings->set(static::LOGO_FILE_NAME_KEY, null, 'Logo file name');
        $settings->forgetCache();

        $this->data['logo_file'] = null;
        $this->data['logo_upload'] = static::logoUploadState(null);

        Notification::make()
            ->title(__('laravel-crm-filament::labels.actions.remove_logo_done'))
            ->success()
            ->send();
    }

    /**
     * Language options, built by scanning the locale directories that actually
     * ship — this plugin's `resources/lang` (en/es/fr) plus base's
     * (en/en_au/en_gb) — rather than base's hardcoded English-only list.
     *
     * `en` is normalised to `english` because that is the value base seeds
     * (`Settings` middleware / `config('laravel-crm.language')`) and stores, so
     * an existing `language` setting keeps resolving to a real option.
     *
     * @return array<string, string>
     */
    protected static function languageOptions(): array
    {
        $options = [];

        foreach (static::languageDirectories() as $directory) {
            foreach ((array) glob($directory . '/*', GLOB_ONLYDIR) as $path) {
                $locale = static::normaliseLocale(basename((string) $path));
                $options[$locale] = static::localeLabel($locale);
            }
        }

        // Always offer the stored default even if no lang directory was found
        // (e.g. a host that published/pruned the translations).
        $options['english'] ??= static::localeLabel('english');

        asort($options);

        // Pin the default first, then the rest alphabetically by label.
        return ['english' => $options['english']] + $options;
    }

    /**
     * @return array<int, string>
     */
    protected static function languageDirectories(): array
    {
        $directories = [dirname(__DIR__, 2) . '/resources/lang'];

        if (class_exists(Setting::class)) {
            $file = (new \ReflectionClass(Setting::class))->getFileName();
            if (is_string($file)) {
                // .../laravel-crm/src/Models/Setting.php -> .../laravel-crm
                $directories[] = dirname($file, 3) . '/resources/lang';
            }
        }

        return array_values(array_filter($directories, 'is_dir'));
    }

    protected static function normaliseLocale(string $locale): string
    {
        return $locale === 'en' ? 'english' : $locale;
    }

    protected static function localeLabel(string $locale): string
    {
        $labels = [
            'english' => 'English',
            'en_au' => 'English (Australia)',
            'en_gb' => 'English (United Kingdom)',
            'es' => 'Spanish',
            'fr' => 'French',
        ];

        return $labels[$locale] ?? ucfirst(str_replace('_', '-', $locale));
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
