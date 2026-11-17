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
use Illuminate\Support\Facades\Response;
use VentureDrake\LaravelCrm\Mail\SendQuote;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\PipelineStage;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrm\Services\QuoteStateService;
use VentureDrake\LaravelCrm\Support\RecordSearch;
use VentureDrake\LaravelCrmFilament\Concerns\ExportsCsv;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\LeadDealContactSection;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\LineItemsRepeater;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\MoneyTotalsRow;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\SalesDetailsSection;
use VentureDrake\LaravelCrmFilament\Concerns\HasCrmCustomFieldEntries;
use VentureDrake\LaravelCrmFilament\Concerns\HasCrmCustomFields;
use VentureDrake\LaravelCrmFilament\Concerns\HasEncryptedGlobalSearch;
use VentureDrake\LaravelCrmFilament\Concerns\HasLabels;
use VentureDrake\LaravelCrmFilament\Concerns\HasPdfPreviewAction;
use VentureDrake\LaravelCrmFilament\Concerns\HasPortalLinkAction;
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
use VentureDrake\LaravelCrmFilament\Support\DocumentMail;
use VentureDrake\LaravelCrmFilament\Support\EncryptedContactSearch;
use VentureDrake\LaravelCrmFilament\Support\PortalMessage;
use VentureDrake\LaravelCrmFilament\Support\PortalUrl;

use function VentureDrake\LaravelCrm\Http\Helpers\CheckAmount\subTotal;
use function VentureDrake\LaravelCrm\Http\Helpers\CheckAmount\total;

class QuoteResource extends Resource
{
    use HasCrmCustomFieldEntries;
    use HasCrmCustomFields;
    use HasEncryptedGlobalSearch;
    use HasLabels;
    use HasPdfPreviewAction;
    use HasPortalLinkAction;
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
                    // Core 2.7.0's RecordSearch: "QU-3002", "3002" and "qu-3002"
                    // all find the quote, including one stored with no quote_id
                    // or under an older prefix; `%` and `_` are literal.
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $q) => RecordSearch::orNumber($q, $search)))
                    ->sortable(),

                Tables\Columns\TextColumn::make('reference')
                    ->label(__('laravel-crm-filament::labels.fields.reference'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $q) => RecordSearch::orLike($q, $q->getModel()->qualifyColumn('reference'), $search)))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('title')
                    ->sortable()
                    ->limit(50)
                    ->searchable(query: fn (Builder $query, string $search): Builder => EncryptedContactSearch::titleOrContact($query, $search)),

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
                // Core 2.7.0's index offers the reversals on the row too.
                static::unacceptAction()
                    ->button(),
                static::unrejectAction()
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
                static::pdfPreviewAction('quote')
                    ->button()
                    ->hiddenLabel()
                    ->icon('heroicon-m-document-magnifying-glass'),
                static::portalLinkAction('laravel-crm.portal.quotes.show')
                    ->button()
                    ->hiddenLabel()
                    ->icon('heroicon-m-link'),
                Actions\ViewAction::make()
                    ->button()
                    ->hiddenLabel(),
                static::lockAwareEditAction(Actions\EditAction::make())
                    ->button()
                    ->hiddenLabel(),
                static::deleteBlockedTooltip(Actions\DeleteAction::make())
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

    /**
     * The one Send action for quotes: the table row, View and Edit all use it.
     *
     * As core 2.7.0's QuoteSend: the To field takes a list of addresses, a mail
     * failure is shown and leaves the modal open, and only a quote that was
     * actually sent moves from Draft to Sent (QuoteStateService::markSent).
     */
    public static function sendActionFactory(): Action
    {
        return Action::make('send')
            ->label(__('laravel-crm-filament::labels.actions.send'))
            ->icon('heroicon-o-paper-airplane')
            ->color('success')
            ->modalHeading('Send quote')
            ->modalSubmitActionLabel('Send')
            ->schema(fn (Quote $record): array => [
                DocumentMail::toField(fn (): ?string => $record->person?->getPrimaryEmail()?->address),
                TextInput::make('subject')
                    ->required()
                    // The quote number, never the reference: core 2.7.0 fixed a
                    // subject that read "Quote  from …" for a quote with none.
                    ->default(fn (): string => 'Quote ' . $record->quote_id),
                Textarea::make('message')
                    ->rows(8)
                    ->default(fn (): string => PortalMessage::defaultMessage(PortalMessage::QUOTE)),
                Checkbox::make('cc')
                    ->label(__('laravel-crm-filament::labels.campaign.send_me_a_copy')),
            ])
            ->action(function (array $data, Quote $record, Action $action): void {
                static::dispatchQuoteSend($record, $data, $action);

                app(QuoteStateService::class)->markSent($record);

                Notification::make()
                    ->title(ucfirst(__('laravel-crm::lang.quote_sent_successfully')))
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
            // Signed, not bare: the portal controller 401s an unsigned request.
            ->url(fn (Quote $record): ?string => PortalUrl::signedFor('laravel-crm.portal.quotes.show', $record))
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

    /**
     * Returns only once the mail was handed to the transport; a refused
     * connection or a rejected address halts $action with a notification.
     *
     * @param  array<string, mixed>  $data
     */
    protected static function dispatchQuoteSend(Quote $record, array $data, ?Action $action = null): void
    {
        $signedUrl = PortalUrl::signedFor('laravel-crm.portal.quotes.show', $record);

        $pdfPath = static::renderQuotePdfToDisk($record);

        DocumentMail::sendOrHalt(new SendQuote([
            'to' => DocumentMail::recipients($data['to'] ?? null),
            'subject' => $data['subject'],
            // Null link, no placeholder: base's mailable expands it either way,
            // and an unexpanded null mails an empty anchor.
            'message' => PortalMessage::resolve(PortalMessage::QUOTE, (string) ($data['message'] ?? ''), $signedUrl),
            'cc' => ! empty($data['cc']) ? 1 : 0,
            'onlineQuoteLink' => $signedUrl ?? '',
            'pdf' => $pdfPath,
        ]), $action, 'quote', $record);
    }

    protected static function renderQuotePdfToDisk(Quote $record): string
    {
        return CrmPdf::renderToDisk($record, 'quote');
    }

    /**
     * Core 2.7.0's QuoteShow/QuoteIndex offer no response while a quote's
     * amounts don't add up (they link to the edit form instead), unless the
     * quote is already locked and so can't be fixed.
     */
    protected static function responseBlockedByAmounts(Quote $quote): bool
    {
        return ! $quote->isLocked() && (! subTotal($quote) || ! total($quote));
    }

    /**
     * Accept, reject, unaccept and unreject all go through core's
     * QuoteStateService, which moves the pipeline stage with the timestamp,
     * and are shown by the same rules as core 2.7.0's QuoteShow.
     */
    protected static function stateAction(string $name, string $icon, string $color): Action
    {
        return Action::make($name)
            ->label(__("laravel-crm-filament::labels.actions.{$name}"))
            ->icon($icon)
            ->color($color)
            ->requiresConfirmation()
            // Core authorizes every response on update(), not edit(): an
            // accepted quote is locked, yet can still be unaccepted.
            ->authorize('update');
    }

    public static function acceptAction(): Action
    {
        return static::stateAction('accept', 'heroicon-o-check-circle', 'success')
            ->visible(fn (?Quote $record): bool => $record !== null
                && $record->accepted_at === null
                && $record->rejected_at === null
                && ! static::responseBlockedByAmounts($record))
            ->action(function (Quote $record): void {
                app(QuoteStateService::class)->accept($record);

                Notification::make()
                    ->title(ucfirst(__('laravel-crm::lang.quote_accepted')))
                    ->success()
                    ->send();
            });
    }

    public static function rejectAction(): Action
    {
        return static::stateAction('reject', 'heroicon-o-x-circle', 'danger')
            ->visible(fn (?Quote $record): bool => $record !== null
                && $record->accepted_at === null
                && $record->rejected_at === null
                && ! static::responseBlockedByAmounts($record))
            ->action(function (Quote $record): void {
                app(QuoteStateService::class)->reject($record);

                Notification::make()
                    ->title(ucfirst(__('laravel-crm::lang.quote_rejected')))
                    ->success()
                    ->send();
            });
    }

    public static function unacceptAction(): Action
    {
        return static::stateAction('unaccept', 'heroicon-o-arrow-uturn-left', 'gray')
            ->visible(fn (?Quote $record): bool => $record !== null
                && $record->accepted_at !== null
                && ! $record->hasOrders())
            ->action(function (Quote $record, Action $action): void {
                // An order was made from what was accepted since the page
                // was drawn; the service refuses and changes nothing.
                if (! app(QuoteStateService::class)->unaccept($record)) {
                    Notification::make()
                        ->title(ucfirst(__('laravel-crm::lang.quote_locked_ordered')))
                        ->danger()
                        ->send();

                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title(ucfirst(__('laravel-crm::lang.quote_unaccepted')))
                    ->success()
                    ->send();
            });
    }

    public static function unrejectAction(): Action
    {
        return static::stateAction('unreject', 'heroicon-o-arrow-uturn-left', 'gray')
            // A quote that is both accepted and rejected is unaccepted first.
            ->visible(fn (?Quote $record): bool => $record !== null
                && $record->rejected_at !== null
                && $record->accepted_at === null
                && ! static::responseBlockedByAmounts($record))
            ->action(function (Quote $record): void {
                app(QuoteStateService::class)->unreject($record);

                Notification::make()
                    ->title(ucfirst(__('laravel-crm::lang.quote_unrejected')))
                    ->success()
                    ->send();
            });
    }

    /**
     * An accepted or ordered quote is locked (core 2.7.0). Its Edit button
     * stays, disabled, with the reason as the tooltip, as core's does.
     */
    public static function lockAwareEditAction(Actions\EditAction $action): Actions\EditAction
    {
        return $action
            ->disabled(fn (Quote $record): bool => $record->isLocked())
            ->tooltip(fn (Quote $record): ?string => $record->lockReason());
    }

    /**
     * Core's QuotePolicy::delete() denies a quote with an order with
     * deleteBlockedReason(); the tooltip shows that on a disabled Delete.
     */
    public static function deleteBlockedTooltip(Actions\DeleteAction $action): Actions\DeleteAction
    {
        return $action->authorizationTooltip();
    }

    public static function canEdit(Model $record): bool
    {
        /** @var Quote $record */
        return parent::canEdit($record) && ! $record->isLocked();
    }

    /**
     * "Name (deleted)" for a soft-deleted contact, as core 2.7.0's record link.
     */
    public static function contactLabel(Person | Organization | null $contact, string $name): ?string
    {
        if ($contact === null) {
            return null;
        }

        return $contact->trashed()
            ? $name . ' (' . __('laravel-crm::lang.deleted') . ')'
            : $name;
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
                    // Through the *WithTrashed relations (core 2.7.0), so a
                    // deleted contact still shows, marked and without a link.
                    TextEntry::make('person.name')
                        ->label(__('laravel-crm-filament::labels.fields.contact'))
                        ->state(function (?Quote $record): ?string {
                            $person = $record?->personWithTrashed;

                            return $person instanceof Person
                                ? static::contactLabel($person, LeadDealContactSection::personLabel($person))
                                : null;
                        })
                        ->url(function (?Quote $record): ?string {
                            $person = $record?->personWithTrashed;

                            return $person instanceof Person && ! $person->trashed()
                                ? PersonResource::getUrl('view', ['record' => $person])
                                : null;
                        }),

                    TextEntry::make('organization.name')
                        ->label(__('laravel-crm-filament::labels.fields.organization'))
                        ->state(function (?Quote $record): ?string {
                            $organization = $record?->organizationWithTrashed;

                            return $organization instanceof Organization
                                ? static::contactLabel($organization, (string) $organization->getAttribute('name'))
                                : null;
                        })
                        ->url(function (?Quote $record): ?string {
                            $organization = $record?->organizationWithTrashed;

                            return $organization instanceof Organization && ! $organization->trashed()
                                ? OrganizationResource::getUrl('view', ['record' => $organization])
                                : null;
                        }),
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
