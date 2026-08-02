<?php

namespace VentureDrake\LaravelCrmFilament\Resources\People;

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
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrm\Models\Person;
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
use VentureDrake\LaravelCrmFilament\Resources\People\Pages\CreatePerson;
use VentureDrake\LaravelCrmFilament\Resources\People\Pages\EditPerson;
use VentureDrake\LaravelCrmFilament\Resources\People\Pages\ListPeople;
use VentureDrake\LaravelCrmFilament\Resources\People\Pages\ViewPerson;

class PersonResource extends Resource
{
    use HasCrmCustomFieldEntries;
    use HasCrmCustomFields;
    use HasEncryptedGlobalSearch;
    use HasLabels;
    use UsesExternalIdRouting;

    protected static ?string $model = Person::class;

    protected static ?string $slug = 'people';

    protected static ?string $recordTitleAttribute = 'first_name';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-user';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return LaravelCrmPlugin::get()->getNavigationGroup() ?? 'Contacts';
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Person::query()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'gray';
    }

    public static function form(Schema $schema): Schema
    {
        $components = [
            Grid::make(2)->schema([
                Forms\Components\TextInput::make('first_name')->maxLength(255),
                Forms\Components\TextInput::make('last_name')->maxLength(255),
            ]),

            Grid::make(2)->schema([
                Forms\Components\TextInput::make('middle_name')->maxLength(255),
                Forms\Components\TextInput::make('maiden_name')->maxLength(255),
            ]),

            Grid::make(3)->schema([
                Forms\Components\TextInput::make('title')->maxLength(50),
                Forms\Components\Select::make('gender')->options([
                    'male' => 'Male',
                    'female' => 'Female',
                    'other' => 'Other',
                ]),
                Forms\Components\DatePicker::make('birthday'),
            ]),

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

        if ($customFields = static::crmCustomFieldsSection(Person::class)) {
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
                    ->limit(40),

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

                Tables\Columns\TextColumn::make('email')
                    ->label(__('laravel-crm-filament::labels.fields.email'))
                    ->state(fn ($record) => $record?->getPrimaryEmail()?->address),

                Tables\Columns\TextColumn::make('phone')
                    ->label(__('laravel-crm-filament::labels.fields.phone'))
                    ->state(fn ($record) => $record?->getPrimaryPhone()?->number),

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
                    $accessor = HasEncryptedSearch::modifyQuery(
                        fn ($r) => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')),
                        ['first_name', 'last_name']
                    );
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
                            'First name' => fn ($r) => $r->first_name,
                            'Last name' => fn ($r) => $r->last_name,
                            'Organization' => fn ($r) => optional($r->organization)->name,
                            'Owner' => fn ($r) => optional($r->ownerUser)->name,
                            'Created' => fn ($r) => $r->created_at,
                        ],
                        filename: 'people',
                    ),
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('laravel-crm-filament::labels.sections.identity'))
                ->schema(fn (?Person $record) => array_merge(
                    static::personDetailEntries($record),
                    $record ? static::crmCustomFieldEntries($record, false) : [],
                )),

            Section::make(__('laravel-crm-filament::labels.sections.custom_fields'))
                ->schema(fn (?Person $record) => $record ? static::crmCustomFieldEntries($record, true) : [])
                ->hidden(function ($record): bool {
                    if (! $record instanceof Person) {
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
    protected static function personDetailEntries(?Person $record): array
    {
        $entries = [
            TextEntry::make('first_name')
                ->label(__('laravel-crm-filament::labels.fields.first_name')),

            TextEntry::make('middle_name')
                ->label(__('laravel-crm-filament::labels.fields.middle_name')),

            TextEntry::make('last_name')
                ->label(__('laravel-crm-filament::labels.fields.last_name')),

            TextEntry::make('gender')
                ->label(__('laravel-crm-filament::labels.fields.gender'))
                ->formatStateUsing(fn ($state) => $state ? ucfirst((string) $state) : null),

            TextEntry::make('birthday')
                ->label(__('laravel-crm-filament::labels.fields.birthday')),

            TextEntry::make('description')
                ->label(__('laravel-crm-filament::labels.fields.description'))
                ->columnSpanFull(),
        ];

        if (! $record instanceof Person) {
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

    protected static function formatAddresses(Person $record): ?string
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
        return ['first_name', 'last_name', 'middle_name', 'maiden_name'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return trim((string) ($record->name ?? ($record->first_name . ' ' . $record->last_name)));
    }

    protected static function crmEncryptedSearchAccessor(): \Closure
    {
        return fn ($r) => trim((($r->first_name ?? '') . ' ' . ($r->middle_name ?? '') . ' ' . ($r->last_name ?? '') . ' ' . ($r->maiden_name ?? '')));
    }

    public static function getRecordTitle(?Model $record): string | Htmlable | null
    {
        if (! $record) {
            return static::getModelLabel();
        }

        $composed = trim(implode(' ', array_filter([
            $record->first_name ?? null,
            $record->last_name ?? null,
        ])));

        return $composed !== '' ? $composed : ($record->name ?? static::getModelLabel());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPeople::route('/'),
            'create' => CreatePerson::route('/create'),
            'view' => ViewPerson::route('/{record}'),
            'edit' => EditPerson::route('/{record}/edit'),
        ];
    }

    public static function backToIndexAction(): Action
    {
        return Action::make('backToIndex')
            ->label(__('laravel-crm-filament::labels.actions.back_to_people'))
            ->icon('heroicon-o-arrow-left')
            ->color('gray')
            ->url(static::getUrl('index'));
    }
}
