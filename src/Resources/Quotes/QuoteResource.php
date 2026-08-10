<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Quotes;

use BackedEnum;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\URL;
use VentureDrake\LaravelCrm\Mail\SendQuote;
use VentureDrake\LaravelCrm\Models\PipelineStage;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrmFilament\Concerns\ExportsCsv;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\LeadDealContactSection;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\LineItemsRepeater;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\MoneyTotalsRow;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\SalesDetailsSection;
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
use VentureDrake\LaravelCrmFilament\Resources\Organizations\OrganizationResource;
use VentureDrake\LaravelCrmFilament\Resources\People\PersonResource;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\CreateQuote;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\EditQuote;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\ListQuotes;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\QuoteKanban;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\ViewQuote;
use VentureDrake\LaravelCrmFilament\Support\CrmMoney;
use VentureDrake\LaravelCrmFilament\Support\CrmPdf;
use VentureDrake\LaravelCrmFilament\Support\PortalUrl;

class QuoteResource extends Resource
{
    use HasCrmCustomFieldEntries;
    use HasCrmCustomFields;
    use HasEncryptedGlobalSearch;
    use HasLabels;
    use HasPrimaryBulkActions;
    use UsesExternalIdRouting;

    protected static ?string $model = Quote::class;

    protected static ?string $slug = 'quotes';

    protected static ?string $recordTitleAttribute = 'title';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 50;

    public static function getNavigationGroup(): ?string
    {
        return LaravelCrmPlugin::get()->getNavigationGroup() ?? 'Sales';
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Quote::query()->whereNull('accepted_at')->whereNull('rejected_at')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        $details = SalesDetailsSection::make([
            'title' => true,
            'description' => true,
            'reference' => true,
            'currency' => true,
            'issueDateKey' => 'issue_at',
            'expiryDateKey' => 'expire_at',
            'terms' => true,
            'stage' => true,
            'owner' => true,
            'pdfTemplate' => 'quote',
            'labels' => true,
            'labelsField' => fn () => static::labelsField(),
            'customFields' => static::crmCustomFieldsSection(Quote::class),
        ]);

        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 2])->columnSpanFull()->schema([
                Grid::make(1)
                    ->columnSpan(['lg' => 1])
                    ->schema([$details]),

                Section::make(__('laravel-crm-filament::labels.sections.products'))
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        LineItemsRepeater::products(
                            fkColumn: 'quote_product_id',
                            priceField: 'unit_price',
                        )->defaultItems(1),
                        MoneyTotalsRow::make(),
                    ]),
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

                Tables\Columns\TextColumn::make('quote_id')
                    ->label(__('laravel-crm-filament::labels.fields.number'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('reference')
                    ->label(__('laravel-crm-filament::labels.fields.reference'))
                    ->toggleable(),

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

                Tables\Columns\TextColumn::make('person.name')
                    ->label(__('laravel-crm-filament::labels.fields.contact'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('organization.name')
                    ->label(__('laravel-crm-filament::labels.fields.organization'))
                    ->toggleable(),

                CrmMoney::column('total')
                    ->label(__('laravel-crm-filament::labels.money.total'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('issue_at')
                    ->label(__('laravel-crm-filament::labels.money.issue_date'))
                    ->date()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('expire_at')
                    ->label(__('laravel-crm-filament::labels.money.expiry_date'))
                    ->date()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('pipelineStage.name')
                    ->label(__('laravel-crm-filament::labels.sales.stage'))
                    ->badge()
                    ->sortable(),

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

                Tables\Filters\SelectFilter::make('pipeline_stage_id')
                    ->label(__('laravel-crm-filament::labels.sales.stage'))
                    ->options(fn () => PipelineStage::query()->orderBy('order')->pluck('name', 'id')),
            ])
            ->recordActions([
                static::sendActionFactory()
                    ->button()
                    ->color('gray'),
                static::acceptAction()
                    ->button(),
                static::rejectAction()
                    ->button(),
                static::portalActionFactory()
                    ->button()
                    ->hiddenLabel()
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->color('gray'),
                static::downloadPdfActionFactory()
                    ->button()
                    ->hiddenLabel()
                    ->icon('heroicon-m-arrow-down-tray'),
                Actions\ViewAction::make()
                    ->button()
                    ->hiddenLabel(),
                Actions\EditAction::make()
                    ->button()
                    ->hiddenLabel(),
                Actions\DeleteAction::make()
                    ->button()
                    ->requiresConfirmation()
                    ->hiddenLabel(),
            ])
            ->toolbarActions([
                static::primaryBulkActionGroup(),
                Actions\BulkActionGroup::make([
                    ExportsCsv::action(
                        columns: [
                            'ID' => fn ($r) => $r->quote_id,
                            'Title' => fn ($r) => $r->title,
                            'Contact' => fn ($r) => optional($r->person)->name,
                            'Organization' => fn ($r) => optional($r->organization)->name,
                            'Total' => fn ($r) => ($r->total ?? 0) / 100,
                            'Currency' => fn ($r) => $r->currency,
                            'Issue date' => fn ($r) => $r->issue_at,
                            'Expiry date' => fn ($r) => $r->expire_at,
                            'Stage' => fn ($r) => optional($r->pipelineStage)->name,
                            'Owner' => fn ($r) => optional($r->ownerUser)->name,
                        ],
                        filename: 'quotes',
                    ),
                ]),
            ]);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['quote_id', 'title'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return (string) ($record->title ?? $record->getKey());
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter(['ID' => $record->quote_id]);
    }

    protected static function crmEncryptedSearchAccessor(): \Closure
    {
        return fn ($r) => trim(($r->quote_id ?? '') . ' ' . ($r->title ?? ''));
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

    public static function getPages(): array
    {
        return [
            'index' => ListQuotes::route('/'),
            'kanban' => QuoteKanban::route('/kanban'),
            'create' => CreateQuote::route('/create'),
            'view' => ViewQuote::route('/{record}'),
            'edit' => EditQuote::route('/{record}/edit'),
        ];
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

    public static function sendActionFactory(): Action
    {
        return Action::make('send')
            ->label(__('laravel-crm-filament::labels.actions.send'))
            ->icon('heroicon-o-paper-airplane')
            ->color('success')
            ->modalHeading('Send quote')
            ->modalSubmitActionLabel('Send')
            ->schema(fn (Quote $record): array => [
                TextInput::make('to')
                    ->label(__('laravel-crm-filament::labels.campaign.to'))
                    ->email()
                    ->required()
                    ->default(fn () => optional($record->person)->getPrimaryEmail()?->address),
                TextInput::make('subject')
                    ->required()
                    ->default(fn () => 'Quote ' . $record->quote_id),
                Textarea::make('message')
                    ->rows(8)
                    ->default("Hi,\n\nPlease find your quote here: [Online Quote Link]\n\nThanks."),
                Checkbox::make('cc')
                    ->label(__('laravel-crm-filament::labels.campaign.send_me_a_copy')),
            ])
            ->action(function (array $data, Quote $record): void {
                static::dispatchQuoteSend($record, $data);

                Notification::make()
                    ->title('Quote sent')
                    ->success()
                    ->send();
            });
    }

    public static function portalActionFactory(): Action
    {
        return Action::make('previewPortal')
            ->label(__('laravel-crm-filament::labels.actions.preview_portal'))
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->color('primary')
            ->visible(fn (): bool => PortalUrl::exists('laravel-crm.portal.quotes.show'))
            ->url(fn (Quote $record): ?string => PortalUrl::for('laravel-crm.portal.quotes.show', $record))
            ->openUrlInNewTab();
    }

    public static function downloadPdfActionFactory(): Action
    {
        return Action::make('downloadPdf')
            ->label(__('laravel-crm-filament::labels.actions.download_pdf'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->action(function (Quote $record) {
                $relative = static::renderQuotePdfToDisk($record);

                return Response::download(
                    storage_path($relative),
                    'quote-' . strtolower((string) ($record->quote_id ?? $record->external_id)) . '.pdf',
                );
            });
    }

    protected static function dispatchQuoteSend(Quote $record, array $data): void
    {
        $signedUrl = URL::temporarySignedRoute(
            'laravel-crm.portal.quotes.show',
            now()->addDays(14),
            ['quote' => $record],
        );

        $pdfPath = static::renderQuotePdfToDisk($record);

        Mail::send(new SendQuote([
            'to' => $data['to'],
            'subject' => $data['subject'],
            'message' => $data['message'],
            'cc' => ! empty($data['cc']) ? 1 : 0,
            'onlineQuoteLink' => $signedUrl,
            'pdf' => $pdfPath,
        ]));
    }

    protected static function renderQuotePdfToDisk(Quote $record): string
    {
        return CrmPdf::renderToDisk($record, 'quote');
    }

    public static function acceptAction(): Action
    {
        return Action::make('accept')
            ->label(__('laravel-crm-filament::labels.actions.accept'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (?Quote $record): bool => $record !== null && $record->accepted_at === null)
            ->action(function (Quote $record): void {
                $record->forceFill([
                    'accepted_at' => now(),
                    'rejected_at' => null,
                ])->save();

                Notification::make()
                    ->title(__('laravel-crm-filament::labels.actions.accept'))
                    ->success()
                    ->send();
            });
    }

    public static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label(__('laravel-crm-filament::labels.actions.reject'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (?Quote $record): bool => $record !== null && $record->rejected_at === null)
            ->action(function (Quote $record): void {
                $record->forceFill([
                    'rejected_at' => now(),
                    'accepted_at' => null,
                ])->save();

                Notification::make()
                    ->title(__('laravel-crm-filament::labels.actions.reject'))
                    ->success()
                    ->send();
            });
    }

    public static function unacceptAction(): Action
    {
        return Action::make('unaccept')
            ->label(__('laravel-crm-filament::labels.actions.unaccept'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->requiresConfirmation()
            ->visible(fn (?Quote $record): bool => $record !== null && $record->accepted_at !== null)
            ->action(function (Quote $record): void {
                $record->forceFill([
                    'accepted_at' => null,
                ])->save();

                Notification::make()
                    ->title(__('laravel-crm-filament::labels.actions.unaccept'))
                    ->success()
                    ->send();
            });
    }

    public static function unrejectAction(): Action
    {
        return Action::make('unreject')
            ->label(__('laravel-crm-filament::labels.actions.unreject'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->requiresConfirmation()
            ->visible(fn (?Quote $record): bool => $record !== null && $record->rejected_at !== null)
            ->action(function (Quote $record): void {
                $record->forceFill([
                    'rejected_at' => null,
                ])->save();

                Notification::make()
                    ->title(__('laravel-crm-filament::labels.actions.unreject'))
                    ->success()
                    ->send();
            });
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('laravel-crm-filament::labels.sections.details'))
                ->schema(fn (?Quote $record) => array_merge([
                    TextEntry::make('created_at')
                        ->label(__('laravel-crm-filament::labels.fields.created'))
                        ->since(),

                    TextEntry::make('quote_id')
                        ->label(__('laravel-crm-filament::labels.fields.number')),

                    TextEntry::make('reference')
                        ->label(__('laravel-crm-filament::labels.fields.reference')),

                    TextEntry::make('title')
                        ->label(__('laravel-crm-filament::labels.fields.title')),

                    TextEntry::make('description')
                        ->label(__('laravel-crm-filament::labels.fields.description'))
                        ->columnSpanFull(),

                    CrmMoney::entry('total')
                        ->label(__('laravel-crm-filament::labels.money.total')),

                    TextEntry::make('issue_at')
                        ->label(__('laravel-crm-filament::labels.money.issue_date'))
                        ->date(),

                    TextEntry::make('expire_at')
                        ->label(__('laravel-crm-filament::labels.money.expiry_date'))
                        ->date(),

                    TextEntry::make('pipelineStage.name')
                        ->label(__('laravel-crm-filament::labels.sales.stage'))
                        ->badge(),

                    TextEntry::make('labels.name')
                        ->label(__('laravel-crm-filament::labels.fields.labels'))
                        ->badge(),

                    TextEntry::make('ownerUser.name')
                        ->label(__('laravel-crm-filament::labels.fields.owner'))
                        ->placeholder(__('laravel-crm-filament::labels.misc.unallocated')),
                ], $record ? static::crmCustomFieldEntries($record, false) : [])),

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
                ]),

            Section::make(__('laravel-crm-filament::labels.sections.custom_fields'))
                ->schema(fn (?Quote $record) => $record ? static::crmCustomFieldEntries($record, true) : [])
                ->hidden(function ($record): bool {
                    if (! $record instanceof Quote) {
                        return true;
                    }

                    return ! $record->fields()
                        ->whereHas('field', fn ($q) => $q->whereNotNull('field_group_id'))
                        ->exists();
                }),
        ])->columns(1);
    }

    public static function backToIndexAction(): Action
    {
        return Action::make('backToIndex')
            ->label(__('laravel-crm-filament::labels.actions.back_to_quotes'))
            ->icon('heroicon-o-arrow-left')
            ->color('gray')
            ->url(static::getUrl('index'));
    }
}
