<?php

namespace VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders;

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
use Illuminate\Support\Facades\Response;
use VentureDrake\LaravelCrm\Mail\SendPurchaseOrder;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\PurchaseOrder;
use VentureDrake\LaravelCrm\Support\RecordSearch;
use VentureDrake\LaravelCrmFilament\Concerns\ExportsCsv;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\LeadDealContactSection;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\LineItemsRepeater;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\MoneyTotalsRow;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\PurchaseOrderDeliverySection;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\SalesDetailsSection;
use VentureDrake\LaravelCrmFilament\Concerns\HasCrmCustomFieldEntries;
use VentureDrake\LaravelCrmFilament\Concerns\HasCrmCustomFields;
use VentureDrake\LaravelCrmFilament\Concerns\HasLabels;
use VentureDrake\LaravelCrmFilament\Concerns\HasPdfPreviewAction;
use VentureDrake\LaravelCrmFilament\Concerns\HasPortalLinkAction;
use VentureDrake\LaravelCrmFilament\Concerns\HasPrimaryBulkActions;
use VentureDrake\LaravelCrmFilament\Concerns\HasXeroSyncStateInfolist;
use VentureDrake\LaravelCrmFilament\Concerns\UsesExternalIdRouting;
use VentureDrake\LaravelCrmFilament\LaravelCrmPlugin;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmActivitiesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmCallsRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmFilesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmLunchesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmMeetingsRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmNotesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmTasksRelationManager;
use VentureDrake\LaravelCrmFilament\Resources\Orders\OrderResource;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\OrganizationResource;
use VentureDrake\LaravelCrmFilament\Resources\People\PersonResource;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use VentureDrake\LaravelCrmFilament\Support\CrmMoney;
use VentureDrake\LaravelCrmFilament\Support\CrmPdf;
use VentureDrake\LaravelCrmFilament\Support\DocumentMail;
use VentureDrake\LaravelCrmFilament\Support\PurchaseOrderPortalLink;

class PurchaseOrderResource extends Resource
{
    use HasCrmCustomFieldEntries;
    use HasCrmCustomFields;
    use HasLabels;
    use HasPdfPreviewAction;
    use HasPortalLinkAction;
    use HasPrimaryBulkActions;
    use HasXeroSyncStateInfolist;
    use UsesExternalIdRouting;

    protected static ?string $model = PurchaseOrder::class;

    protected static ?string $slug = 'purchase-orders';

    protected static ?string $recordTitleAttribute = 'purchase_order_id';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 53;

    public static function getNavigationGroup(): ?string
    {
        return LaravelCrmPlugin::get()->getNavigationGroup() ?? 'Sales';
    }

    public static function getNavigationBadge(): ?string
    {
        $count = PurchaseOrder::query()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'info';
    }

    public static function form(Schema $schema): Schema
    {
        $details = SalesDetailsSection::make([
            'title' => false,
            'description' => false,
            'reference' => true,
            'currency' => true,
            'issueDateKey' => 'issue_date',
            'expiryDateKey' => 'delivery_date',
            'terms' => true,
            'stage' => false,
            'owner' => true,
            'pdfTemplate' => 'purchase-order',
            'labels' => true,
            'labelsField' => fn () => static::labelsField(),
            'orderLink' => true,
            'customFields' => static::crmCustomFieldsSection(PurchaseOrder::class),
        ]);

        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 2])->columnSpanFull()->schema([
                Grid::make(1)
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        $details,
                        PurchaseOrderDeliverySection::make(),
                    ]),

                Section::make(__('laravel-crm-filament::labels.sections.products'))
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        LineItemsRepeater::products(
                            fkColumn: 'purchase_order_line_id',
                            priceField: 'unit_price',
                        )->defaultItems(1),
                        MoneyTotalsRow::make(withAdjustments: false),
                    ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Edit and Delete hide on a PO already pushed to Xero; without
            // this each of them asked the database per row.
            ->modifyQueryUsing(fn (Builder $query) => $query->with('xeroPurchaseOrder'))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('laravel-crm-filament::labels.fields.created'))
                    ->since()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('purchase_order_id')
                    ->label(__('laravel-crm-filament::labels.fields.number'))
                    // Core 2.7.0's RecordSearch: the stored number and its
                    // prefix/number parts, so "PO-1001" and "1001" both find it.
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where(fn (Builder $query) => RecordSearch::orNumber($query, $search)))
                    ->sortable(),

                Tables\Columns\TextColumn::make('reference')
                    ->label(__('laravel-crm-filament::labels.fields.reference'))
                    // Matched literally and case-insensitively, as core does.
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where(fn (Builder $query) => RecordSearch::orLike($query, $query->qualifyColumn('reference'), $search)))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('order.order_id')
                    ->label(__('laravel-crm-filament::labels.fields.order'))
                    ->formatStateUsing(fn ($state, PurchaseOrder $record): string => static::trashedLabel((string) $state, static::parentOrder($record)?->trashed() ?? false))
                    ->url(fn (PurchaseOrder $record): ?string => static::orderUrl($record))
                    ->color(fn (PurchaseOrder $record): ?string => static::orderUrl($record) !== null ? 'primary' : null)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('person.name')
                    ->label(__('laravel-crm-filament::labels.fields.contact'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('organization.name')
                    ->label(__('laravel-crm-filament::labels.fields.organization'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('issue_date')
                    ->label(__('laravel-crm-filament::labels.money.issue_date'))
                    ->date()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('delivery_date')
                    ->label(__('laravel-crm-filament::labels.money.delivery_date'))
                    ->date()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('delivery_type')
                    ->label(__('laravel-crm-filament::labels.sales.delivery_type'))
                    ->formatStateUsing(fn (?string $state): ?string => PurchaseOrderDeliverySection::typeLabel($state))
                    ->toggleable(),

                CrmMoney::column('total')
                    ->label(__('laravel-crm-filament::labels.money.amount'))
                    ->sortable(),

                Tables\Columns\IconColumn::make('sent')
                    ->label(__('laravel-crm-filament::labels.fields.sent'))
                    ->boolean()
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
            ])
            ->recordActions([
                static::sendPurchaseOrderActionFactory()
                    ->button()
                    ->label(__('laravel-crm-filament::labels.actions.send'))
                    ->color('gray'),
                static::downloadPurchaseOrderPdfActionFactory()
                    ->button()
                    ->hiddenLabel()
                    ->icon('heroicon-m-arrow-down-tray'),
                static::pdfPreviewAction('purchaseorder')
                    ->button()
                    ->hiddenLabel()
                    ->icon('heroicon-m-document-magnifying-glass'),
                static::portalLinkAction(PurchaseOrderPortalLink::ROUTE)
                    ->button()
                    ->hiddenLabel()
                    ->icon('heroicon-m-link'),
                static::purchaseOrderPortalActionFactory()
                    ->button()
                    ->hiddenLabel()
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->color('gray'),
                Actions\ViewAction::make()
                    ->button()
                    ->hiddenLabel(),
                Actions\EditAction::make()
                    ->button()
                    ->hidden(fn (?PurchaseOrder $record) => $record?->xeroPurchaseOrder !== null)
                    ->hiddenLabel(),
                Actions\DeleteAction::make()
                    ->button()
                    ->requiresConfirmation()
                    ->hidden(fn (?PurchaseOrder $record) => $record?->xeroPurchaseOrder !== null)
                    ->hiddenLabel(),
            ])
            ->toolbarActions([
                static::primaryBulkActionGroup(),
                Actions\BulkActionGroup::make([
                    ExportsCsv::action(
                        columns: [
                            'ID' => fn ($r) => $r->purchase_order_id,
                            'Reference' => fn ($r) => $r->reference,
                            'Supplier' => fn ($r) => optional($r->organization)->name,
                            'Issue date' => fn ($r) => $r->issue_date,
                            'Delivery date' => fn ($r) => $r->delivery_date,
                            'Total' => fn ($r) => ($r->total ?? 0) / 100,
                            'Currency' => fn ($r) => $r->currency,
                            'Owner' => fn ($r) => optional($r->ownerUser)->name,
                        ],
                        filename: 'purchase-orders',
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

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('laravel-crm-filament::labels.sections.details'))
                ->schema(fn (?PurchaseOrder $record) => array_merge([
                    TextEntry::make('created_at')
                        ->label(__('laravel-crm-filament::labels.fields.created'))
                        ->since(),

                    TextEntry::make('purchase_order_id')
                        ->label(__('laravel-crm-filament::labels.fields.number')),

                    TextEntry::make('reference')
                        ->label(__('laravel-crm-filament::labels.fields.reference')),

                    TextEntry::make('order.order_id')
                        ->label(__('laravel-crm-filament::labels.fields.order'))
                        ->formatStateUsing(fn ($state, PurchaseOrder $record): string => static::trashedLabel((string) $state, static::parentOrder($record)?->trashed() ?? false))
                        ->url(fn (PurchaseOrder $record): ?string => static::orderUrl($record)),

                    TextEntry::make('issue_date')
                        ->label(__('laravel-crm-filament::labels.money.issue_date'))
                        ->date(),

                    TextEntry::make('delivery_date')
                        ->label(__('laravel-crm-filament::labels.money.delivery_date'))
                        ->date(),

                    TextEntry::make('delivery_type')
                        ->label(__('laravel-crm-filament::labels.sales.delivery_type'))
                        ->formatStateUsing(fn (?string $state): ?string => PurchaseOrderDeliverySection::typeLabel($state)),

                    CrmMoney::entry('total')
                        ->label(__('laravel-crm-filament::labels.money.amount')),

                    TextEntry::make('terms')
                        ->label(__('laravel-crm-filament::labels.fields.terms'))
                        ->columnSpanFull(),

                    TextEntry::make('labels.name')
                        ->label(__('laravel-crm-filament::labels.fields.labels'))
                        ->badge(),

                    TextEntry::make('ownerUser.name')
                        ->label(__('laravel-crm-filament::labels.fields.owner'))
                        ->placeholder(__('laravel-crm-filament::labels.misc.unallocated')),
                ], $record ? static::crmCustomFieldEntries($record, false) : [])),

            Section::make(__('laravel-crm-filament::labels.sections.contact'))
                ->schema([
                    // Core 2.7.0 shows a deleted contact as "Name (deleted)"
                    // with no link, rather than a blank.
                    TextEntry::make('personWithTrashed.name')
                        ->label(__('laravel-crm-filament::labels.fields.contact'))
                        ->state(fn (PurchaseOrder $record): ?string => ($person = static::contactPerson($record))
                            ? static::trashedLabel(LeadDealContactSection::personLabel($person), $person->trashed())
                            : null)
                        ->url(fn (PurchaseOrder $record): ?string => ($person = static::contactPerson($record)) && ! $person->trashed()
                            ? PersonResource::getUrl('view', ['record' => $person])
                            : null),

                    TextEntry::make('organizationWithTrashed.name')
                        ->label(__('laravel-crm-filament::labels.fields.organization'))
                        ->state(fn (PurchaseOrder $record): ?string => ($organization = static::contactOrganization($record))
                            ? static::trashedLabel((string) $organization->getAttribute('name'), $organization->trashed())
                            : null)
                        ->url(fn (PurchaseOrder $record): ?string => ($organization = static::contactOrganization($record)) && ! $organization->trashed()
                            ? OrganizationResource::getUrl('view', ['record' => $organization])
                            : null),
                ]),

            Section::make(__('laravel-crm-filament::labels.sections.custom_fields'))
                ->schema(fn (?PurchaseOrder $record) => $record ? static::crmCustomFieldEntries($record, true) : [])
                ->hidden(function ($record): bool {
                    if (! $record instanceof PurchaseOrder) {
                        return true;
                    }

                    return ! $record->fields()
                        ->whereHas('field', fn ($q) => $q->whereNotNull('field_group_id'))
                        ->exists();
                }),

            static::xeroSyncStateSection(fn (PurchaseOrder $po) => $po->xeroPurchaseOrder),
        ])->columns(1);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseOrders::route('/'),
            'create' => CreatePurchaseOrder::route('/create'),
            'view' => ViewPurchaseOrder::route('/{record}'),
            'edit' => EditPurchaseOrder::route('/{record}/edit'),
        ];
    }

    /**
     * The one Send action, used by the table row and by the View and Edit
     * pages (through HasPurchaseOrderSendAction), as core 2.7.0's
     * PurchaseOrderSend does it: the To field takes a list of addresses, a
     * mail failure keeps the modal open with a danger notification, and only
     * a send that went marks the PO as sent.
     */
    public static function sendPurchaseOrderActionFactory(): Action
    {
        return Action::make('send')
            ->label(__('laravel-crm-filament::labels.actions.send'))
            ->icon('heroicon-o-paper-airplane')
            ->color('success')
            ->modalHeading(__('laravel-crm-filament::labels.actions.send_purchase_order'))
            ->modalSubmitActionLabel(__('laravel-crm-filament::labels.actions.send'))
            ->schema(fn (PurchaseOrder $record): array => [
                DocumentMail::toField(fn (): ?string => static::defaultRecipient($record)),
                TextInput::make('subject')
                    ->required()
                    ->default(fn () => 'Purchase Order ' . $record->purchase_order_id),
                Textarea::make('message')
                    ->rows(8)
                    ->default(fn (): string => PurchaseOrderPortalLink::defaultMessage()),
                Checkbox::make('cc')
                    ->label(__('laravel-crm-filament::labels.campaign.send_me_a_copy')),
            ])
            ->action(function (array $data, PurchaseOrder $record, Action $action): void {
                static::dispatchPurchaseOrderSend($record, $data, $action);

                // The Sent column reads this flag, and nothing else sets it.
                $record->update(['sent' => 1]);

                Notification::make()
                    ->title(ucfirst(__('laravel-crm::lang.purchase_order_sent')))
                    ->success()
                    ->send();
            });
    }

    public static function purchaseOrderPortalActionFactory(): Action
    {
        return Action::make('previewPortal')
            ->label(__('laravel-crm-filament::labels.actions.preview_portal'))
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->color('primary')
            // Same gate the Order and Delivery preview actions use: base does
            // not register a purchase-order portal route, and an action with a
            // null URL renders as a button that does nothing on click.
            ->visible(fn (): bool => PurchaseOrderPortalLink::available())
            // Signed, not bare: the portal controller 401s an unsigned request.
            ->url(fn (PurchaseOrder $record): ?string => PurchaseOrderPortalLink::signedFor($record))
            ->openUrlInNewTab();
    }

    public static function downloadPurchaseOrderPdfActionFactory(): Action
    {
        return Action::make('downloadPdf')
            ->label(__('laravel-crm-filament::labels.actions.download_pdf'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->action(function (PurchaseOrder $record) {
                $relative = static::renderPurchaseOrderPdfToDisk($record);

                return Response::download(
                    storage_path($relative),
                    'purchase-order-' . strtolower((string) ($record->purchase_order_id ?? $record->external_id)) . '.pdf',
                );
            });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function dispatchPurchaseOrderSend(PurchaseOrder $record, array $data, ?Action $action = null): void
    {
        $signedUrl = PurchaseOrderPortalLink::signedFor($record);

        $message = (string) ($data['message'] ?? '');

        if ($signedUrl === null) {
            $message = PurchaseOrderPortalLink::stripPlaceholder($message);
        }

        $pdfPath = static::renderPurchaseOrderPdfToDisk($record);

        DocumentMail::sendOrHalt(new SendPurchaseOrder([
            'to' => DocumentMail::recipients($data['to'] ?? null),
            'subject' => $data['subject'],
            'message' => $message,
            'cc' => ! empty($data['cc']) ? 1 : 0,
            'onlinePurchaseOrderLink' => $signedUrl ?? '',
            'pdf' => $pdfPath,
        ]), $action, 'purchase_order', $record);
    }

    /**
     * The parent order's View URL, or null when there is none or it has been
     * deleted: core 2.7.0 shows a soft-deleted parent as "No. (deleted)"
     * without a link, as there is no page left to open.
     */
    public static function orderUrl(PurchaseOrder $record): ?string
    {
        $order = static::parentOrder($record);

        return $order && ! $order->trashed()
            ? OrderResource::getUrl('view', ['record' => $order])
            : null;
    }

    /**
     * The order the PO was made from, a soft-deleted one included.
     */
    protected static function parentOrder(PurchaseOrder $record): ?Order
    {
        /** @var Order|null $order */
        $order = $record->getRelationValue('order');

        return $order;
    }

    /**
     * The PO's contact person, a soft-deleted one included (core 2.7.0's
     * display-only HasTrashedContacts relation).
     */
    protected static function contactPerson(PurchaseOrder $record): ?Person
    {
        /** @var Person|null $person */
        $person = $record->getRelationValue('personWithTrashed');

        return $person;
    }

    protected static function contactOrganization(PurchaseOrder $record): ?Organization
    {
        /** @var Organization|null $organization */
        $organization = $record->getRelationValue('organizationWithTrashed');

        return $organization;
    }

    /**
     * The To field's default: the contact's primary email, as core's
     * PurchaseOrderSend starts it.
     */
    protected static function defaultRecipient(PurchaseOrder $record): ?string
    {
        /** @var Person|null $person */
        $person = $record->getRelationValue('person');
        $email = $person?->getPrimaryEmail();

        return $email ? (string) $email->getAttribute('address') : null;
    }

    /**
     * "$label (deleted)" for a soft-deleted record, as core's record links
     * badge it.
     */
    public static function trashedLabel(string $label, bool $trashed): string
    {
        return $trashed
            ? trim($label . ' (' . __('laravel-crm::lang.deleted') . ')')
            : $label;
    }

    protected static function renderPurchaseOrderPdfToDisk(PurchaseOrder $record): string
    {
        return CrmPdf::renderToDisk($record, 'purchaseorder');
    }

    public static function backToIndexAction(): Action
    {
        return Action::make('backToIndex')
            ->label(__('laravel-crm-filament::labels.actions.back_to_purchase_orders'))
            ->color('gray')
            ->icon('heroicon-o-arrow-left')
            ->url(static::getUrl('index'));
    }
}
