<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Organizations;

use BackedEnum;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrm\Models\Industry;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrmFilament\Concerns\ContactFieldsSchema;
use VentureDrake\LaravelCrmFilament\Concerns\ExportsCsv;
use VentureDrake\LaravelCrmFilament\Concerns\HasCrmCustomFieldEntries;
use VentureDrake\LaravelCrmFilament\Concerns\HasCrmCustomFields;
use VentureDrake\LaravelCrmFilament\Concerns\HasEncryptedGlobalSearch;
use VentureDrake\LaravelCrmFilament\Concerns\HasEncryptedSearch;
use VentureDrake\LaravelCrmFilament\Concerns\HasLabels;
use VentureDrake\LaravelCrmFilament\Concerns\UsesExternalIdRouting;
use VentureDrake\LaravelCrmFilament\LaravelCrmPlugin;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmActivitiesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmCallsRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmFilesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmLunchesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmMeetingsRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmNotesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmTasksRelationManager;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\Pages\CreateOrganization;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\Pages\EditOrganization;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\Pages\ListOrganizations;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\Pages\ViewOrganization;

class OrganizationResource extends Resource
{
    use HasCrmCustomFieldEntries;
    use HasCrmCustomFields;
    use HasEncryptedGlobalSearch;
    use HasLabels;
    use UsesExternalIdRouting;

    protected static ?string $model = Organization::class;

    protected static ?string $slug = 'organizations';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-building-office';

    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): ?string
    {
        return LaravelCrmPlugin::get()->getNavigationGroup() ?? 'Contacts';
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Organization::query()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'gray';
    }

    public static function form(Schema $schema): Schema
    {
        $components = [
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(255),

            Grid::make(2)->schema([
                Forms\Components\TextInput::make('vat_number')->maxLength(50),
                Forms\Components\TextInput::make('number_of_employees')->numeric(),
            ]),

            Grid::make(2)->schema([
                Forms\Components\TextInput::make('annual_revenue')->numeric(),
                Forms\Components\TextInput::make('total_money_raised')->numeric(),
            ]),

            Forms\Components\Select::make('industry_id')
                ->label(__('laravel-crm-filament::labels.money.industry'))
                ->options(fn () => Industry::query()->orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->preload(),

            Forms\Components\TextInput::make('linkedin')
                ->url()
                ->maxLength(255),

            Forms\Components\Textarea::make('description')
                ->rows(3)
                ->columnSpanFull(),

            Forms\Components\Select::make('user_owner_id')
                ->label(__('laravel-crm-filament::labels.fields.owner'))
                ->relationship('ownerUser', 'name')
                ->searchable()
                ->preload(),

            static::labelsField(),

            ContactFieldsSchema::phonesRepeater(),
            ContactFieldsSchema::emailsRepeater(),
            ContactFieldsSchema::addressesRepeater(),
        ];

        if ($customFields = static::crmCustomFieldsSection(Organization::class)) {
            $components[] = $customFields;
        }

        return $schema->components($components);
    }

    public static function table(Table $table): Table
    {
        $encrypted = config('laravel-crm.encrypt_db_fields', false);

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('laravel-crm-filament::labels.fields.name'))
                    ->sortable(! $encrypted)
                    ->searchable(! $encrypted)
                    ->limit(60),

                Tables\Columns\IconColumn::make('xero_contact')
                    ->label('')
                    ->state(fn ($record) => $record?->xeroContact !== null)
                    ->boolean()
                    ->visible(fn (): bool => LaravelCrmPlugin::get()->isModuleEnabled('xero')),

                Tables\Columns\TextColumn::make('organizationType.name')
                    ->label(__('laravel-crm-filament::labels.fields.type'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('labels.name')
                    ->label(__('laravel-crm-filament::labels.fields.labels'))
                    ->badge()
                    ->color(function ($state, $record) {
                        $label = $record?->labels?->firstWhere('name', $state);
                        $hex = $label?->hex;

                        if (! $hex) {
                            return 'gray';
                        }

                        return '#' . ltrim($hex, '#');
                    })
                    ->limitList(3),

                Tables\Columns\TextColumn::make('open_deals_count')
                    ->label(__('laravel-crm-filament::labels.fields.open_deals'))
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('lost_deals_count')
                    ->label(__('laravel-crm-filament::labels.fields.lost_deals'))
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('won_deals_count')
                    ->label(__('laravel-crm-filament::labels.fields.won_deals'))
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('next_activity')
                    ->label(__('laravel-crm-filament::labels.fields.next_activity'))
                    ->state(fn ($record) => $record?->tasks()
                        ->whereNull('completed_at')
                        ->where('due_at', '>=', now())
                        ->orderBy('due_at')
                        ->first()?->due_at)
                    ->dateTime(),

                Tables\Columns\TextColumn::make('ownerUser.name')
                    ->label(__('laravel-crm-filament::labels.fields.owner'))
                    ->placeholder(__('laravel-crm-filament::labels.misc.unallocated'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('laravel-crm-filament::labels.fields.created'))
                    ->since()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(function (Builder $query) use ($encrypted) {
                $query->withCount([
                    'deals as open_deals_count' => fn ($q) => $q->whereNull('closed_at'),
                    'deals as lost_deals_count' => fn ($q) => $q->where('closed_status', 'lost'),
                    'deals as won_deals_count' => fn ($q) => $q->where('closed_status', 'won'),
                ]);

                if ($encrypted) {
                    $accessor = HasEncryptedSearch::modifyQuery(fn ($r) => $r->name ?? '', ['name']);
                    $accessor($query);
                }

                return $query;
            })
            ->recordActions([
                Actions\ViewAction::make()
                    ->button()
                    ->hiddenLabel(),
                Actions\EditAction::make()
                    ->button()
                    ->hiddenLabel(),
                Actions\DeleteAction::make()
                    ->button()
                    ->hiddenLabel(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    ExportsCsv::action(
                        columns: [
                            'Name' => fn ($r) => $r->name,
                            'VAT' => fn ($r) => $r->vat,
                            'Employees' => fn ($r) => $r->employees,
                            'Revenue' => fn ($r) => $r->revenue,
                            'Created' => fn ($r) => $r->created_at,
                        ],
                        filename: 'organizations',
                    ),
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('laravel-crm-filament::labels.sections.details'))
                ->schema(fn (?Organization $record) => array_merge(
                    static::organizationDetailEntries($record),
                    $record ? static::crmCustomFieldEntries($record, false) : [],
                )),

            Section::make(__('laravel-crm-filament::labels.sections.custom_fields'))
                ->schema(fn (?Organization $record) => $record ? static::crmCustomFieldEntries($record, true) : [])
                ->hidden(function ($record): bool {
                    if (! $record instanceof Organization) {
                        return true;
                    }

                    return ! $record->fields()
                        ->whereHas('field', fn ($q) => $q->whereNotNull('field_group_id'))
                        ->exists();
                }),
        ])->columns(1);
    }

    /**
     * @return array<int, TextEntry>
     */
    protected static function organizationDetailEntries(?Organization $record): array
    {
        $entries = [
            TextEntry::make('organizationType.name')
                ->label(__('laravel-crm-filament::labels.fields.type')),

            TextEntry::make('vat_number')
                ->label(__('laravel-crm-filament::labels.fields.vat_number')),

            TextEntry::make('industry.name')
                ->label(__('laravel-crm-filament::labels.money.industry')),

            TextEntry::make('timezone.name')
                ->label(__('laravel-crm-filament::labels.fields.timezone')),

            TextEntry::make('number_of_employees')
                ->label(__('laravel-crm-filament::labels.fields.number_of_employees')),

            TextEntry::make('annual_revenue')
                ->label(__('laravel-crm-filament::labels.fields.annual_revenue'))
                ->money(fn ($record) => $record?->currency ?: config('laravel-crm.default_currency', 'USD')),

            TextEntry::make('linkedin')
                ->label(__('laravel-crm-filament::labels.fields.linkedin'))
                ->state(fn ($record) => $record?->linkedin
                    ? 'https://linkedin.com/company/' . $record->linkedin
                    : null)
                ->url(fn ($record) => $record?->linkedin
                    ? 'https://linkedin.com/company/' . $record->linkedin
                    : null, shouldOpenInNewTab: true),

            TextEntry::make('description')
                ->label(__('laravel-crm-filament::labels.fields.description'))
                ->columnSpanFull(),
        ];

        if (! $record instanceof Organization) {
            return $entries;
        }

        foreach ($record->phones as $i => $phone) {
            $typePrefix = $phone->type ? ucfirst($phone->type) . ' ' : '';
            $entries[] = TextEntry::make('phone_' . $i)
                ->label($typePrefix . __('laravel-crm-filament::labels.fields.phone'))
                ->state(trim(($phone->number ?? '') . ($phone->primary ? ' (Primary)' : '')));
        }

        foreach ($record->emails as $i => $email) {
            $typePrefix = $email->type ? ucfirst($email->type) . ' ' : '';
            $entries[] = TextEntry::make('email_' . $i)
                ->label($typePrefix . __('laravel-crm-filament::labels.fields.email'))
                ->state(trim(($email->address ?? '') . ($email->primary ? ' (Primary)' : '')));
        }

        foreach ($record->addresses as $i => $address) {
            $typePrefix = $address->addressType?->name ? ucfirst($address->addressType->name) . ' ' : '';
            $line = trim((string) static::formatAddressLine($address));
            if ($address->primary) {
                $line = trim($line . ' (Primary)');
            }
            $entries[] = TextEntry::make('address_' . $i)
                ->label($typePrefix . __('laravel-crm-filament::labels.fields.address'))
                ->state($line)
                ->columnSpanFull();
        }

        $entries[] = TextEntry::make('labels.name')
            ->label(__('laravel-crm-filament::labels.fields.labels'))
            ->badge()
            ->color(function ($state, $record) {
                $label = $record?->labels?->firstWhere('name', $state);
                $hex = $label?->hex;

                if (! $hex) {
                    return 'gray';
                }

                return '#' . ltrim($hex, '#');
            });

        $entries[] = TextEntry::make('integrations')
            ->label(__('laravel-crm-filament::labels.fields.integrations'))
            ->state(fn ($record) => $record?->xeroContact !== null ? 'Xero' : null);

        $entries[] = TextEntry::make('ownerUser.name')
            ->label(__('laravel-crm-filament::labels.fields.owner'))
            ->placeholder(__('laravel-crm-filament::labels.misc.unallocated'));

        return $entries;
    }

    protected static function formatAddressLine($address): ?string
    {
        $parts = array_filter([
            $address->line1 ?? null,
            $address->line2 ?? null,
            $address->line3 ?? null,
            $address->city ?? null,
            $address->state ?? null,
            $address->code ?? null,
            $address->country ?? null,
        ]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    protected static function formatAddresses(Organization $record): ?string
    {
        $addresses = $record->addresses()->get();

        if ($addresses->isEmpty()) {
            return null;
        }

        $lines = $addresses->map(function ($address) {
            $parts = array_filter([
                $address->line1 ?? null,
                $address->city ?? null,
                $address->state ?? null,
                $address->code ?? null,
                $address->country ?? null,
            ]);

            return $parts === [] ? null : implode(', ', $parts);
        })->filter()->values();

        return $lines->isEmpty() ? null : $lines->implode("\n");
    }

    public static function getRelations(): array
    {
        return [
            CrmActivitiesRelationManager::class,
            CrmNotesRelationManager::class,
            CrmTasksRelationManager::class,
            CrmCallsRelationManager::class,
            CrmMeetingsRelationManager::class,
            CrmLunchesRelationManager::class,
            CrmFilesRelationManager::class,
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return (string) ($record->name ?? '');
    }

    protected static function crmEncryptedSearchAccessor(): \Closure
    {
        return fn ($r) => (string) ($r->name ?? '');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrganizations::route('/'),
            'create' => CreateOrganization::route('/create'),
            'view' => ViewOrganization::route('/{record}'),
            'edit' => EditOrganization::route('/{record}/edit'),
        ];
    }

    public static function backToIndexAction(): Action
    {
        return Action::make('backToIndex')
            ->label(__('laravel-crm-filament::labels.actions.back_to_organizations'))
            ->icon('heroicon-o-arrow-left')
            ->color('gray')
            ->url(static::getUrl('index'));
    }
}
