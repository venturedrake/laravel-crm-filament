<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Orders;

use BackedEnum;
use Barryvdh\DomPDF\Facade\Pdf;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Services\DeliveryService;
use VentureDrake\LaravelCrm\Services\PurchaseOrderService;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\LeadDealContactSection;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\LineItemsRepeater;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\MoneyTotalsRow;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\OrderAddressTabs;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\SalesDetailsSection;
use VentureDrake\LaravelCrmFilament\Concerns\HasCrmCustomFieldEntries;
use VentureDrake\LaravelCrmFilament\Concerns\HasCrmCustomFields;
use VentureDrake\LaravelCrmFilament\Concerns\HasEncryptedGlobalSearch;
use VentureDrake\LaravelCrmFilament\Concerns\HasLabels;
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
use VentureDrake\LaravelCrmFilament\Support\FormPayload;

class OrderResource extends Resource
{
    use HasCrmCustomFieldEntries;
    use HasCrmCustomFields;
    use HasEncryptedGlobalSearch;
    use HasLabels;
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

    public static function form(Schema $schema): Schema
    {
        $details = SalesDetailsSection::make([
            'title' => false,
            'description' => true,
            'reference' => true,
            'currency' => true,
            'issueDateKey' => null,
            'expiryDateKey' => null,
            'terms' => false,
            'stage' => false,
            'owner' => true,
            'labels' => true,
            'labelsField' => fn () => static::labelsField(),
            'customFields' => static::crmCustomFieldsSection(Order::class),
        ]);

        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 2])->columnSpanFull()->schema([
                Grid::make(1)
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        $details,
                        OrderAddressTabs::make(),
                    ]),

                Section::make(__('laravel-crm-filament::labels.sections.products'))
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        LineItemsRepeater::products('order_product_id', 'unit_price')->defaultItems(1),
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

                Tables\Columns\TextColumn::make('order_id')
                    ->label(__('laravel-crm-filament::labels.fields.number'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('reference')
                    ->label(__('laravel-crm-filament::labels.fields.reference'))
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('quote.quote_id')
                    ->label(__('laravel-crm-filament::labels.money.quote'))
                    ->url(fn ($record) => $record->quote
                        ? QuoteResource::getUrl('view', ['record' => $record->quote])
                        : null)
                    ->color('primary')
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

                Tables\Columns\TextColumn::make('subtotal')
                    ->label(__('laravel-crm-filament::labels.money.subtotal'))
                    ->money(fn ($record) => $record->currency ?: config('laravel-crm.default_currency', 'USD'))
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('tax')
                    ->label(__('laravel-crm-filament::labels.money.tax'))
                    ->money(fn ($record) => $record->currency ?: config('laravel-crm.default_currency', 'USD'))
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('total')
                    ->label(__('laravel-crm-filament::labels.money.total'))
                    ->money(fn ($record) => $record->currency ?: config('laravel-crm.default_currency', 'USD'))
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
                        ->url(fn ($record) => $record?->quote
                            ? QuoteResource::getUrl('view', ['record' => $record->quote])
                            : null),

                    TextEntry::make('subtotal')
                        ->label(__('laravel-crm-filament::labels.money.subtotal'))
                        ->money(fn ($record) => $record?->currency ?: config('laravel-crm.default_currency', 'USD')),

                    TextEntry::make('tax')
                        ->label(__('laravel-crm-filament::labels.money.tax'))
                        ->money(fn ($record) => $record?->currency ?: config('laravel-crm.default_currency', 'USD')),

                    TextEntry::make('total')
                        ->label(__('laravel-crm-filament::labels.money.total'))
                        ->money(fn ($record) => $record?->currency ?: config('laravel-crm.default_currency', 'USD')),

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
            ->modalDescription('Pre-fills the full ordered quantity for every line item.')
            ->action(function (Order $record, DeliveryService $deliveryService): void {
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
                        \Filament\Notifications\Actions\Action::make('open')
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
                        \Filament\Notifications\Actions\Action::make('open')
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

    protected static function buildDeliveryPayloadFromOrderStatic(Order $record): array
    {
        $products = [];

        foreach ($record->orderProducts as $orderProduct) {
            $products[] = [
                'order_product_id' => $orderProduct->id,
                'quantity' => $orderProduct->quantity,
            ];
        }

        return [
            'order_id' => $record->id,
            'delivery_expected' => now(),
            'delivered_on' => null,
            'user_owner_id' => $record->user_owner_id,
            'products' => $products,
            'addresses' => [],
        ];
    }

    protected static function buildPurchaseOrderPayloadFromOrderStatic(Order $record): array
    {
        $products = [];

        foreach ($record->orderProducts as $orderProduct) {
            $unitPrice = static::resolveSupplierUnitPriceStatic($orderProduct->product_id, $orderProduct->price);
            $quantity = (float) $orderProduct->quantity;
            $amount = $unitPrice * $quantity;

            $products[] = [
                'id' => $orderProduct->product_id,
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
            'delivery_type' => 'collect',
            'delivery_instructions' => null,
            'terms' => null,
            'user_owner_id' => $record->user_owner_id,
            'products' => $products,
        ];
    }

    protected static function resolveSupplierUnitPriceStatic(?int $productId, ?int $orderProductPriceCents): float
    {
        if ($productId && $product = Product::find($productId)) {
            $price = $product->getDefaultPrice();

            if ($price && $price->cost_per_unit) {
                return $price->cost_per_unit / 100;
            }

            if ($price && $price->unit_price) {
                return $price->unit_price / 100;
            }
        }

        return $orderProductPriceCents !== null ? $orderProductPriceCents / 100 : 0;
    }

    protected static function renderOrderPdfToDisk(Order $record): string
    {
        $settings = app('laravel-crm.settings');

        $data = [
            'order' => $record,
            'dateFormat' => $settings->get('date_format', config('laravel-crm.date_format')),
            'email' => optional($record->person)->getPrimaryEmail(),
            'phone' => optional($record->person)->getPrimaryPhone(),
            'address' => optional($record->person)->getPrimaryAddress(),
            'organization_address' => optional($record->organization)->getPrimaryAddress(),
            'fromName' => $settings->get('organization_name'),
            'logo' => $settings->get('logo_file'),
        ];

        $relativeDir = 'laravel-crm/order/' . $record->id;
        Storage::makeDirectory($relativeDir);

        $filename = 'order-' . strtolower((string) ($record->order_id ?? $record->external_id)) . '.pdf';
        $pdfRelative = 'app/' . $relativeDir . '/' . $filename;

        Pdf::setOption(['fontDir' => public_path('vendor/laravel-crm/fonts')])
            ->loadView('laravel-crm::orders.pdf', $data)
            ->save(storage_path($pdfRelative));

        return $pdfRelative;
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
