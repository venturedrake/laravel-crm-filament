<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Orders;

use BackedEnum;
use Filament\Actions;
use Filament\Actions\Action;
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
use VentureDrake\LaravelCrm\Models\DeliveryProduct;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\OrderProduct;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrm\Services\DeliveryService;
use VentureDrake\LaravelCrm\Services\PurchaseOrderService;
use VentureDrake\LaravelCrm\Support\FormOptions;
use VentureDrake\LaravelCrm\Support\LineAmount;
use VentureDrake\LaravelCrm\Support\Money;
use VentureDrake\LaravelCrm\Support\RecordSearch;
use VentureDrake\LaravelCrmFilament\Concerns\ExportsCsv;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\LeadDealContactSection;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\LineItemsRepeater;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\MoneyTotalsRow;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\OrderAddressTabs;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\SalesDetailsSection;
use VentureDrake\LaravelCrmFilament\Concerns\HasCrmCustomFieldEntries;
use VentureDrake\LaravelCrmFilament\Concerns\HasCrmCustomFields;
use VentureDrake\LaravelCrmFilament\Concerns\HasEncryptedGlobalSearch;
use VentureDrake\LaravelCrmFilament\Concerns\HasLabels;
use VentureDrake\LaravelCrmFilament\Concerns\HasPdfPreviewAction;
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
use VentureDrake\LaravelCrmFilament\Resources\Deliveries\DeliveryResource;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\CreateOrder;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\EditOrder;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\ListOrders;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\ViewOrder;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\OrganizationResource;
use VentureDrake\LaravelCrmFilament\Resources\People\PersonResource;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\PurchaseOrderResource;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\QuoteResource;
use VentureDrake\LaravelCrmFilament\Support\CrmMoney;
use VentureDrake\LaravelCrmFilament\Support\CrmPdf;
use VentureDrake\LaravelCrmFilament\Support\FormPayload;
use VentureDrake\LaravelCrmFilament\Support\LineItemVariation;
use VentureDrake\LaravelCrmFilament\Support\MoneyForm;
use VentureDrake\LaravelCrmFilament\Support\OrderDrawdownPrefill;

class OrderResource extends Resource
{
    use HasCrmCustomFieldEntries;
    use HasCrmCustomFields;
    use HasEncryptedGlobalSearch;
    use HasLabels;
    use HasPdfPreviewAction;
    use HasPrimaryBulkActions;
    use HasXeroSyncStateInfolist;
    use UsesExternalIdRouting;

    protected static ?string $model = Order::class;

    protected static ?string $slug = 'orders';

    protected static ?string $recordTitleAttribute = 'order_id';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?int $navigationSort = 51;

    public static function getNavigationGroup(): ?string
    {
        return LaravelCrmPlugin::get()->getNavigationGroup() ?? 'Sales';
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Order::query()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'info';
    }

    /**
     * Core 2.6.0's install-wide order form toggles (config
     * laravel-crm.orders.form). The create and edit pages pass the same
     * object to OrderService, so a field the form hides is left as stored.
     */
    public static function orderForm(): FormOptions
    {
        return FormOptions::for('orders');
    }

    public static function form(Schema $schema): Schema
    {
        $orderForm = static::orderForm();

        $details = SalesDetailsSection::make([
            'title' => false,
            'description' => $orderForm->shows('description'),
            'reference' => true,
            'currency' => true,
            'issueDateKey' => null,
            'expiryDateKey' => null,
            'terms' => false,
            'stage' => false,
            'owner' => true,
            'ownerRequired' => ! $orderForm->allowsUnallocatedOwner(),
            'pdfTemplate' => 'order',
            'labels' => $orderForm->shows('labels'),
            'labelsField' => fn () => static::labelsField(),
            'customFields' => $orderForm->shows('custom_fields')
                ? static::crmCustomFieldsSection(Order::class)
                : null,
        ]);

        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 2])->columnSpanFull()->schema([
                Grid::make(1)
                    ->columnSpan(['lg' => 1])
                    ->schema(array_values(array_filter([
                        $details,
                        OrderAddressTabs::make($orderForm->addressTypes()),
                    ]))),

                Section::make(__('laravel-crm-filament::labels.sections.products'))
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        LineItemsRepeater::products(
                            fkColumn: 'order_product_id',
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
            // Core 2.7.0's Order::deleteBlockedReason() reads these rather
            // than querying three relations per row for the Delete tooltip.
            ->modifyQueryUsing(fn (Builder $query) => $query->withExists(['invoices', 'purchaseOrders', 'deliveries']))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('laravel-crm-filament::labels.fields.created'))
                    ->since()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('order_id')
                    ->label(__('laravel-crm-filament::labels.fields.number'))
                    // Core 2.7.0's RecordSearch: "ORD-1001", "1001" and an
                    // order stored before the prefix changed all match.
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where(fn (Builder $query) => RecordSearch::orNumber($query, $search)))
                    ->sortable(),

                Tables\Columns\TextColumn::make('reference')
                    ->label(__('laravel-crm-filament::labels.fields.reference'))
                    // Matched literally, so `%` or `_` finds itself.
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where(fn (Builder $query) => RecordSearch::orLike($query, $query->qualifyColumn('reference'), $search)))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('quote.quote_id')
                    ->label(__('laravel-crm-filament::labels.money.quote'))
                    // Core 2.7.0's quote relation includes a soft-deleted
                    // quote: shown as "QU-1001 (deleted)", with no link.
                    ->formatStateUsing(fn ($state, Order $record) => static::parentLabel($state, static::quoteOf($record)))
                    ->url(fn (Order $record) => static::quoteUrl($record))
                    ->color(fn (Order $record) => static::quoteUrl($record) ? 'primary' : null)
                    ->toggleable(),

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

                CrmMoney::column('subtotal')
                    ->label(__('laravel-crm-filament::labels.money.subtotal'))
                    ->sortable()
                    ->toggleable(),

                CrmMoney::column('tax')
                    ->label(__('laravel-crm-filament::labels.money.tax'))
                    ->sortable()
                    ->toggleable(),

                CrmMoney::column('total')
                    ->label(__('laravel-crm-filament::labels.money.total'))
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
            ])
            ->recordActions([
                static::convertToDeliveryActionFactory()
                    ->label(__('laravel-crm-filament::labels.actions.delivery'))
                    ->button(),
                static::convertToPurchaseOrderActionFactory()
                    ->label(__('laravel-crm-filament::labels.actions.purchase_order'))
                    ->button(),
                static::downloadOrderPdfActionFactory()
                    ->button()
                    ->hiddenLabel()
                    ->icon('heroicon-m-arrow-down-tray'),
                static::pdfPreviewAction('order')
                    ->button()
                    ->hiddenLabel()
                    ->icon('heroicon-m-document-magnifying-glass'),
                Actions\ViewAction::make()
                    ->button()
                    ->hiddenLabel(),
                Actions\EditAction::make()
                    ->button()
                    ->hiddenLabel(),
                // OrderPolicy::delete refuses an order with invoices, purchase
                // orders or deliveries, with core 2.7.0's reason as the message.
                static::deleteAction(Actions\DeleteAction::make())
                    ->button()
                    ->requiresConfirmation()
                    ->hiddenLabel(),
            ])
            ->toolbarActions([
                static::primaryBulkActionGroup(),
                Actions\BulkActionGroup::make([
                    ExportsCsv::action(
                        columns: [
                            'ID' => fn ($r) => $r->order_id,
                            'Reference' => fn ($r) => $r->reference,
                            'Contact' => fn ($r) => optional($r->person)->name,
                            'Organization' => fn ($r) => optional($r->organization)->name,
                            'Subtotal' => fn ($r) => ($r->subtotal ?? 0) / 100,
                            'Tax' => fn ($r) => ($r->tax ?? 0) / 100,
                            'Total' => fn ($r) => ($r->total ?? 0) / 100,
                            'Currency' => fn ($r) => $r->currency,
                            'Owner' => fn ($r) => optional($r->ownerUser)->name,
                            'Created' => fn ($r) => $r->created_at,
                        ],
                        filename: 'orders',
                    ),
                ]),
            ]);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['order_id', 'reference'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return (string) ($record->order_id ?? $record->getKey());
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter(['Reference' => $record->reference]);
    }

    protected static function crmEncryptedSearchAccessor(): \Closure
    {
        return fn ($r) => trim(($r->order_id ?? '') . ' ' . ($r->reference ?? ''));
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
                ->schema(fn (?Order $record) => array_merge([
                    TextEntry::make('created_at')
                        ->label(__('laravel-crm-filament::labels.fields.created'))
                        ->since(),

                    TextEntry::make('order_id')
                        ->label(__('laravel-crm-filament::labels.fields.number')),

                    TextEntry::make('reference')
                        ->label(__('laravel-crm-filament::labels.fields.reference')),

                    TextEntry::make('description')
                        ->label(__('laravel-crm-filament::labels.fields.description'))
                        ->columnSpanFull(),

                    TextEntry::make('quote.quote_id')
                        ->label(__('laravel-crm-filament::labels.money.quote'))
                        ->formatStateUsing(fn ($state, ?Order $record) => static::parentLabel($state, static::quoteOf($record)))
                        ->url(fn (?Order $record) => $record ? static::quoteUrl($record) : null),

                    CrmMoney::entry('subtotal')
                        ->label(__('laravel-crm-filament::labels.money.subtotal')),

                    CrmMoney::entry('tax')
                        ->label(__('laravel-crm-filament::labels.money.tax')),

                    CrmMoney::entry('total')
                        ->label(__('laravel-crm-filament::labels.money.total')),

                    TextEntry::make('labels.name')
                        ->label(__('laravel-crm-filament::labels.fields.labels'))
                        ->badge(),

                    TextEntry::make('ownerUser.name')
                        ->label(__('laravel-crm-filament::labels.fields.owner'))
                        ->placeholder(__('laravel-crm-filament::labels.misc.unallocated')),
                ], $record ? static::crmCustomFieldEntries($record, false) : [])),

            Section::make(__('laravel-crm-filament::labels.sections.contact'))
                ->schema([
                    // Through core 2.7.0's *WithTrashed relations, so a
                    // deleted contact still shows who the order was for,
                    // marked deleted and with no link to a page that 404s.
                    TextEntry::make('personWithTrashed.name')
                        ->label(__('laravel-crm-filament::labels.fields.contact'))
                        ->state(fn (?Order $record): ?string => static::personEntryState(static::personOf($record)))
                        ->url(fn (?Order $record): ?string => static::personEntryUrl(static::personOf($record))),

                    TextEntry::make('organizationWithTrashed.name')
                        ->label(__('laravel-crm-filament::labels.fields.organization'))
                        ->state(fn (?Order $record): ?string => static::organizationEntryState(static::organizationOf($record)))
                        ->url(fn (?Order $record): ?string => static::organizationEntryUrl(static::organizationOf($record))),
                ]),

            Section::make(__('laravel-crm-filament::labels.sections.custom_fields'))
                ->schema(fn (?Order $record) => $record ? static::crmCustomFieldEntries($record, true) : [])
                ->hidden(function ($record): bool {
                    if (! $record instanceof Order) {
                        return true;
                    }

                    return ! $record->fields()
                        ->whereHas('field', fn ($q) => $q->whereNotNull('field_group_id'))
                        ->exists();
                }),

            static::xeroSyncStateSection(function (Order $order) {
                $invoice = $order->invoices()->latest()->first();

                return $invoice?->xeroInvoice;
            }),
        ])->columns(1);
    }

    /**
     * The Delete action on the table row and the view and edit pages.
     *
     * Core 2.7.0's OrderPolicy::delete denies an order that has invoices,
     * purchase orders or deliveries with Order::deleteBlockedReason() as the
     * message, so the button stays visible but disabled, with that reason as
     * its tooltip. A user without the delete permission gets a denial with no
     * message, and the button stays hidden as before.
     */
    public static function deleteAction(Actions\DeleteAction $action): Actions\DeleteAction
    {
        return $action->authorizationTooltip();
    }

    /**
     * The quote the order was made from, or null when it has none or the
     * quote is soft deleted (core's `quote` relation includes trashed ones).
     */
    protected static function quoteUrl(Order $record): ?string
    {
        $quote = static::quoteOf($record);

        if ($quote === null || $quote->trashed()) {
            return null;
        }

        return QuoteResource::getUrl('view', ['record' => $quote]);
    }

    protected static function quoteOf(?Order $record): ?Quote
    {
        $quote = $record?->getRelationValue('quote');

        return $quote instanceof Quote ? $quote : null;
    }

    /**
     * The order's person, soft-deleted included (core 2.7.0's display-only
     * personWithTrashed relation). Deliveries read their contact through it.
     */
    public static function personOf(?Order $record): ?Person
    {
        $person = $record?->getRelationValue('personWithTrashed');

        return $person instanceof Person ? $person : null;
    }

    public static function organizationOf(?Order $record): ?Organization
    {
        $organization = $record?->getRelationValue('organizationWithTrashed');

        return $organization instanceof Organization ? $organization : null;
    }

    /**
     * "Name", or "Name (deleted)" for a soft-deleted person.
     */
    public static function personEntryState(?Person $person): ?string
    {
        return static::trashedAwareLabel(LeadDealContactSection::personLabel($person), $person);
    }

    /**
     * No link to a soft-deleted person: their page would 404.
     */
    protected static function personEntryUrl(?Person $person): ?string
    {
        return $person && ! $person->trashed()
            ? PersonResource::getUrl('view', ['record' => $person])
            : null;
    }

    public static function organizationEntryState(?Organization $organization): ?string
    {
        return static::trashedAwareLabel((string) ($organization->name ?? ''), $organization);
    }

    protected static function organizationEntryUrl(?Organization $organization): ?string
    {
        return $organization && ! $organization->trashed()
            ? OrganizationResource::getUrl('view', ['record' => $organization])
            : null;
    }

    /**
     * A parent document's number, with core's "(deleted)" marker when the
     * parent is soft deleted.
     */
    public static function parentLabel(mixed $state, ?Model $parent): mixed
    {
        if ($state === null || $state === '' || $parent === null) {
            return $state;
        }

        return static::trashedAwareLabel((string) $state, $parent);
    }

    /**
     * $label, followed by "(deleted)" when $model is soft deleted.
     */
    public static function trashedAwareLabel(string $label, ?Model $model): ?string
    {
        if ($label === '') {
            return null;
        }

        if ($model !== null && method_exists($model, 'trashed') && $model->trashed()) {
            return $label . ' (' . __('laravel-crm::lang.deleted') . ')';
        }

        return $label;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'create' => CreateOrder::route('/create'),
            'view' => ViewOrder::route('/{record}'),
            'edit' => EditOrder::route('/{record}/edit'),
        ];
    }

    public static function convertToDeliveryActionFactory(): Action
    {
        return Action::make('convertToDelivery')
            ->label(__('laravel-crm-filament::labels.actions.convert_to_delivery'))
            ->icon('heroicon-o-truck')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Create delivery from order')
            ->modalDescription('Pre-fills the quantity still outstanding on each line item.')
            ->action(function (Order $record, DeliveryService $deliveryService): void {
                // Same guard as the page header action: an order with nothing
                // outstanding prefills no lines, and an empty Delivery is not a
                // document anyone asked for.
                if (! OrderDrawdownPrefill::hasRemaining($record, DeliveryProduct::class, 'delivery')) {
                    Notification::make()
                        ->title(__('laravel-crm-filament::labels.notifications.nothing_left_to_deliver'))
                        ->body(__('laravel-crm-filament::labels.notifications.nothing_left_to_deliver_body'))
                        ->warning()
                        ->send();

                    return;
                }

                $payload = static::buildDeliveryPayloadFromOrderStatic($record);

                $delivery = $deliveryService->create(
                    FormPayload::wrap($payload),
                    $record->person,
                    $record->organization,
                );

                $url = DeliveryResource::getUrl('view', ['record' => $delivery]);

                Notification::make()
                    ->title('Delivery ' . $delivery->delivery_id . ' created')
                    ->body('Order converted to delivery.')
                    ->success()
                    ->actions([
                        Action::make('open')
                            ->label(__('laravel-crm-filament::labels.actions.open_delivery'))
                            ->url($url),
                    ])
                    ->send();
            });
    }

    public static function convertToPurchaseOrderActionFactory(): Action
    {
        return Action::make('convertToPurchaseOrder')
            ->label(__('laravel-crm-filament::labels.actions.convert_to_purchase_order'))
            ->icon('heroicon-o-clipboard-document-list')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Create purchase order from order')
            ->modalDescription("Copies line items using each product's supplier price (or unit price as fallback).")
            ->action(function (Order $record, PurchaseOrderService $purchaseOrderService): void {
                $payload = static::buildPurchaseOrderPayloadFromOrderStatic($record);

                $purchaseOrder = $purchaseOrderService->create(
                    FormPayload::wrap($payload),
                    $record->person,
                    $record->organization,
                );

                $url = PurchaseOrderResource::getUrl('view', ['record' => $purchaseOrder]);

                Notification::make()
                    ->title('Purchase order ' . $purchaseOrder->purchase_order_id . ' created')
                    ->body('Order converted to purchase order.')
                    ->success()
                    ->actions([
                        Action::make('open')
                            ->label(__('laravel-crm-filament::labels.actions.open_purchase_order'))
                            ->url($url),
                    ])
                    ->send();
            });
    }

    public static function downloadOrderPdfActionFactory(): Action
    {
        return Action::make('downloadPdf')
            ->label(__('laravel-crm-filament::labels.actions.download_pdf'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->action(function (Order $record) {
                $relative = static::renderOrderPdfToDisk($record);

                return Response::download(
                    storage_path($relative),
                    'order-' . strtolower((string) ($record->order_id ?? $record->external_id)) . '.pdf',
                );
            });
    }

    /**
     * Delegates to the one shared drawdown helper — the table row action and
     * the page header action must not disagree about what is outstanding.
     */
    protected static function buildDeliveryPayloadFromOrderStatic(Order $record): array
    {
        return [
            'order_id' => $record->id,
            'delivery_expected' => now(),
            'delivered_on' => null,
            'user_owner_id' => $record->user_owner_id,
            'products' => OrderDrawdownPrefill::deliveryProducts($record),
            'addresses' => [],
        ];
    }

    protected static function buildPurchaseOrderPayloadFromOrderStatic(Order $record): array
    {
        $products = [];

        foreach ($record->orderProducts()->orderBy('order')->orderBy('id')->get() as $orderProduct) {
            $unitPrice = static::resolveSupplierUnitPriceStatic($orderProduct, $record->currency);
            // Integer cents, as PurchaseOrderService prices the saved line.
            $amount = (float) MoneyForm::centsToForm(LineAmount::amountCents(Money::toInteger($unitPrice) ?? 0, $orderProduct->quantity));

            $products[] = [
                'id' => $orderProduct->product_id,
                'product_variation_id' => $orderProduct->product_variation_id,
                'order_product_id' => $orderProduct->id,
                'quantity' => $orderProduct->quantity,
                'unit_price' => $unitPrice,
                'amount' => $amount,
                'comments' => $orderProduct->comments,
            ];
        }

        return [
            'order_id' => $record->id,
            'reference' => $record->reference,
            'issue_date' => now(),
            'delivery_date' => now()->addDays(14),
            'currency' => $record->currency,
            'delivery_type' => 'pickup',
            'delivery_instructions' => null,
            'terms' => null,
            'user_owner_id' => $record->user_owner_id,
            'products' => $products,
        ];
    }

    protected static function resolveSupplierUnitPriceStatic(OrderProduct $orderProduct, ?string $currency): float
    {
        return LineItemVariation::supplierUnitPrice(
            $orderProduct->product_id,
            $orderProduct->product_variation_id,
            $currency,
            $orderProduct->price,
        );
    }

    protected static function renderOrderPdfToDisk(Order $record): string
    {
        return CrmPdf::renderToDisk($record, 'order');
    }

    public static function backToIndexAction(): Action
    {
        return Action::make('backToIndex')
            ->label(__('laravel-crm-filament::labels.actions.back_to_orders'))
            ->color('gray')
            ->icon('heroicon-o-arrow-left')
            ->url(static::getUrl('index'));
    }
}
