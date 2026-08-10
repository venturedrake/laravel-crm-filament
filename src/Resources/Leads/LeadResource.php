<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Leads;

use BackedEnum;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Fluent;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\LeadSource;
use VentureDrake\LaravelCrm\Models\LeadStatus;
use VentureDrake\LaravelCrm\Models\PipelineStage;
use VentureDrake\LaravelCrm\Services\DealService;
use VentureDrake\LaravelCrmFilament\Concerns\ExportsCsv;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\LeadDealContactSection;
use VentureDrake\LaravelCrmFilament\Concerns\HasCrmCustomFieldEntries;
use VentureDrake\LaravelCrmFilament\Concerns\HasCrmCustomFields;
use VentureDrake\LaravelCrmFilament\Concerns\HasEncryptedGlobalSearch;
use VentureDrake\LaravelCrmFilament\Concerns\HasLabels;
use VentureDrake\LaravelCrmFilament\Concerns\HasPrimaryBulkActions;
use VentureDrake\LaravelCrmFilament\Concerns\UsesExternalIdRouting;
use VentureDrake\LaravelCrmFilament\LaravelCrmPlugin;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmActivitiesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmCallsRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmFilesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmLunchesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmMeetingsRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmNotesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmTasksRelationManager;
use VentureDrake\LaravelCrmFilament\Resources\Leads\Pages\CreateLead;
use VentureDrake\LaravelCrmFilament\Resources\Leads\Pages\EditLead;
use VentureDrake\LaravelCrmFilament\Resources\Leads\Pages\LeadKanban;
use VentureDrake\LaravelCrmFilament\Resources\Leads\Pages\ListLeads;
use VentureDrake\LaravelCrmFilament\Resources\Leads\Pages\ViewLead;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\OrganizationResource;
use VentureDrake\LaravelCrmFilament\Resources\People\PersonResource;
use VentureDrake\LaravelCrmFilament\Support\CrmMoney;

class LeadResource extends Resource
{
    use HasCrmCustomFieldEntries;
    use HasCrmCustomFields;
    use HasEncryptedGlobalSearch;
    use HasLabels;
    use HasPrimaryBulkActions;
    use UsesExternalIdRouting;

    protected static ?string $model = Lead::class;

    protected static ?string $slug = 'leads';

    protected static ?string $recordTitleAttribute = 'title';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-funnel';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return LaravelCrmPlugin::get()->getNavigationGroup() ?? 'Sales';
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Lead::query()->whereNull('converted_at')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'primary';
    }

    public static function form(Schema $schema): Schema
    {
        $details = [
            Forms\Components\TextInput::make('title')
                ->required()
                ->maxLength(255),

            Forms\Components\Textarea::make('description')
                ->rows(5)
                ->columnSpanFull(),

            Grid::make(2)->schema([
                Forms\Components\TextInput::make('amount')
                    ->label(__('laravel-crm-filament::labels.money.value'))
                    ->numeric()
                    ->prefix(fn ($get) => $get('currency') ?: config('laravel-crm.default_currency', 'USD')),

                Forms\Components\Select::make('currency')
                    ->label(__('laravel-crm-filament::labels.fields.currency'))
                    ->options(fn () => \VentureDrake\LaravelCrm\Http\Helpers\SelectOptions\currencies())
                    ->searchable()
                    ->default(config('laravel-crm.default_currency', 'USD')),
            ]),

            Forms\Components\Select::make('pipeline_stage_id')
                ->label(__('laravel-crm-filament::labels.sales.pipeline_stage'))
                ->options(fn () => PipelineStage::query()->orderBy('order')->pluck('name', 'id'))
                ->searchable()
                ->preload(),

            Forms\Components\Select::make('lead_source_id')
                ->label(__('laravel-crm-filament::labels.sales.lead_source'))
                ->options(fn () => LeadSource::query()->pluck('name', 'id'))
                ->searchable()
                ->preload(),

            Forms\Components\Select::make('lead_status_id')
                ->label(__('laravel-crm-filament::labels.sales.lead_status'))
                ->options(fn () => LeadStatus::query()->orderBy('order')->pluck('name', 'id'))
                ->searchable()
                ->preload(),

            Forms\Components\Select::make('user_owner_id')
                ->label(__('laravel-crm-filament::labels.fields.owner'))
                ->relationship('ownerUser', 'name')
                ->searchable()
                ->preload(),

            static::labelsField(),
        ];

        if ($customFields = static::crmCustomFieldsSection(Lead::class)) {
            $details[] = $customFields;
        }

        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 2])->columnSpanFull()->schema([
                Grid::make(1)
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        LeadDealContactSection::contactColumn(),
                        LeadDealContactSection::organizationColumn(),
                    ]),

                Section::make(__('laravel-crm-filament::labels.sections.details'))
                    ->columnSpan(['lg' => 1])
                    ->schema($details),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('laravel-crm-filament::labels.fields.created'))
                    ->since()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('lead_id')
                    ->label(__('laravel-crm-filament::labels.fields.number'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('title')
                    ->sortable()
                    ->limit(50)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query
                            ->where('title', 'like', "%{$search}%")
                            ->orWhereHas('person', function (Builder $q) use ($search): void {
                                $q->where('first_name', 'like', "%{$search}%")
                                    ->orWhere('last_name', 'like', "%{$search}%")
                                    ->orWhere('middle_name', 'like', "%{$search}%")
                                    ->orWhere('maiden_name', 'like', "%{$search}%");
                            })
                            ->orWhereHas('organization', function (Builder $q) use ($search): void {
                                $q->where('name', 'like', "%{$search}%");
                            });
                    }),

                Tables\Columns\TextColumn::make('labels.name')
                    ->label(__('laravel-crm-filament::labels.fields.labels'))
                    ->badge()
                    ->limitList(3),

                CrmMoney::column('amount')
                    ->label(__('laravel-crm-filament::labels.money.value'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('person.name')
                    ->label(__('laravel-crm-filament::labels.fields.contact'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('organization.name')
                    ->label(__('laravel-crm-filament::labels.fields.organization'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('pipelineStage.name')
                    ->label(__('laravel-crm-filament::labels.sales.stage'))
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('leadSource.name')
                    ->label(__('laravel-crm-filament::labels.sales.lead_source'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('ownerUser.name')
                    ->label(__('laravel-crm-filament::labels.fields.owner'))
                    ->placeholder(__('laravel-crm-filament::labels.misc.unallocated'))
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('user_owner_id')
                    ->label(__('laravel-crm-filament::labels.fields.owner'))
                    ->multiple()
                    ->relationship('ownerUser', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('labels')
                    ->label(__('laravel-crm-filament::labels.fields.labels'))
                    ->multiple()
                    ->relationship('labels', 'name')
                    ->preload(),

                Tables\Filters\SelectFilter::make('lead_source_id')
                    ->label(__('laravel-crm-filament::labels.sales.lead_source'))
                    ->multiple()
                    ->options(fn () => LeadSource::query()->orderBy('name')->pluck('name', 'id')),

                Tables\Filters\SelectFilter::make('pipeline_stage_id')
                    ->label(__('laravel-crm-filament::labels.sales.stage'))
                    ->options(fn () => PipelineStage::query()->orderBy('order')->pluck('name', 'id')),
            ])
            ->recordActions([
                static::convertAction()
                    ->button()
                    ->label(__('laravel-crm-filament::labels.actions.convert')),
                Actions\ViewAction::make()
                    ->button()
                    ->hiddenLabel(),
                Actions\EditAction::make()
                    ->button()
                    ->hiddenLabel(),
                Actions\DeleteAction::make()
                    ->button()
                    ->hiddenLabel()
                    ->requiresConfirmation(),
            ])
            ->toolbarActions([
                static::primaryBulkActionGroup(withPipelineStage: true),
                Actions\BulkActionGroup::make([
                    ExportsCsv::action(
                        columns: [
                            'ID' => fn ($r) => $r->lead_id,
                            'Title' => fn ($r) => $r->title,
                            'Amount' => fn ($r) => ($r->amount ?? 0) / 100,
                            'Currency' => fn ($r) => $r->currency,
                            'Stage' => fn ($r) => optional($r->pipelineStage)->name,
                            'Owner' => fn ($r) => optional($r->ownerUser)->name,
                            'Created' => fn ($r) => $r->created_at,
                        ],
                        filename: 'leads',
                    ),
                ]),
            ]);
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
        return ['lead_id', 'title'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return (string) ($record->title ?? $record->getKey());
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter(['ID' => $record->lead_id]);
    }

    protected static function crmEncryptedSearchAccessor(): \Closure
    {
        return fn ($r) => trim(($r->lead_id ?? '') . ' ' . ($r->title ?? ''));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeads::route('/'),
            'kanban' => LeadKanban::route('/kanban'),
            'create' => CreateLead::route('/create'),
            'view' => ViewLead::route('/{record}'),
            'edit' => EditLead::route('/{record}/edit'),
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('laravel-crm-filament::labels.sections.details'))
                ->schema(fn (?Lead $record) => array_merge([
                    TextEntry::make('created_at')
                        ->label(__('laravel-crm-filament::labels.fields.created'))
                        ->since(),

                    TextEntry::make('lead_id')
                        ->label(__('laravel-crm-filament::labels.fields.number')),

                    CrmMoney::entry('amount')
                        ->label(__('laravel-crm-filament::labels.money.value')),

                    TextEntry::make('description')
                        ->label(__('laravel-crm-filament::labels.fields.description'))
                        ->columnSpanFull(),

                    TextEntry::make('leadSource.name')
                        ->label(__('laravel-crm-filament::labels.sales.lead_source')),

                    TextEntry::make('labels.name')
                        ->label(__('laravel-crm-filament::labels.fields.labels'))
                        ->badge()
                        ->color(function ($state, $record) {
                            $label = $record?->labels?->firstWhere('name', $state);
                            $hex = $label?->hex;

                            if (! $hex) {
                                return 'gray';
                            }

                            return '#' . ltrim($hex, '#');
                        }),

                    TextEntry::make('ownerUser.name')
                        ->label(__('laravel-crm-filament::labels.fields.owner'))
                        ->placeholder(__('laravel-crm-filament::labels.misc.unallocated')),
                ], $record ? static::crmCustomFieldEntries($record, false) : [])),

            Section::make(__('laravel-crm-filament::labels.sections.lead_qualification'))
                ->schema(fn (?Lead $record) => $record ? static::crmCustomFieldEntries($record, true) : [])
                ->hidden(function ($record): bool {
                    if (! $record instanceof Lead) {
                        return true;
                    }

                    return ! $record->fields()
                        ->whereHas('field', fn ($q) => $q->whereNotNull('field_group_id'))
                        ->exists();
                }),

            Section::make(__('laravel-crm-filament::labels.sections.contact'))
                ->schema([
                    TextEntry::make('person.name')
                        ->label(__('laravel-crm-filament::labels.fields.contact'))
                        ->state(fn ($record) => LeadDealContactSection::personLabel($record?->person))
                        ->url(fn ($record) => $record?->person
                            ? PersonResource::getUrl('view', ['record' => $record->person])
                            : null),

                    TextEntry::make('organization.name')
                        ->label(__('laravel-crm-filament::labels.fields.organization'))
                        ->url(fn ($record) => $record?->organization
                            ? OrganizationResource::getUrl('view', ['record' => $record->organization])
                            : null),

                    TextEntry::make('person_email')
                        ->label(__('laravel-crm-filament::labels.fields.email'))
                        ->state(function ($record) {
                            $email = $record?->person?->emails()->first();

                            if (! $email) {
                                return null;
                            }

                            return trim(($email->address ?? '') . ($email->type ? ' (' . $email->type . ')' : ''));
                        }),

                    TextEntry::make('person_phone')
                        ->label(__('laravel-crm-filament::labels.fields.phone'))
                        ->state(function ($record) {
                            $phone = $record?->person?->phones()->first();

                            if (! $phone) {
                                return null;
                            }

                            return trim(($phone->number ?? '') . ($phone->type ? ' (' . $phone->type . ')' : ''));
                        }),
                ]),
        ])->columns(1);
    }

    public static function doConvertToDeal(Lead $lead): void
    {
        if ($lead->converted_at) {
            return;
        }

        $payload = new Fluent([
            'title' => $lead->title,
            'description' => $lead->description,
            'amount' => ($lead->amount ?? 0) / 100,
            'currency' => $lead->currency,
            'user_owner_id' => $lead->user_owner_id,
        ]);

        app(DealService::class)->create(
            $payload,
            $lead->person,
            $lead->organization,
        );

        $lead->forceFill(['converted_at' => now()])->save();
    }

    public static function convertAction(): Action
    {
        return Action::make('convertToDeal')
            ->label(__('laravel-crm-filament::labels.actions.convert_to_deal'))
            ->icon('heroicon-o-arrow-right-circle')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (?Lead $record): bool => $record !== null && $record->converted_at === null)
            ->action(function (Lead $record): void {
                static::doConvertToDeal($record);

                Notification::make()
                    ->title(__('laravel-crm-filament::labels.actions.convert_to_deal'))
                    ->success()
                    ->send();
            });
    }

    /**
     * @return array<int, Action>
     */
    public static function listKanbanToggleActions(string $current): array
    {
        return [
            Action::make('viewToggle')
                ->label('')
                ->view('laravel-crm-filament::components.list-kanban-toggle', [
                    'current' => $current,
                    'listUrl' => static::getUrl('index'),
                    'kanbanUrl' => static::getUrl('kanban'),
                ]),
        ];
    }

    public static function backToIndexAction(): Action
    {
        return Action::make('backToIndex')
            ->label(__('laravel-crm-filament::labels.actions.back_to_leads'))
            ->icon('heroicon-o-arrow-left')
            ->color('gray')
            ->url(static::getUrl('index'));
    }
}
