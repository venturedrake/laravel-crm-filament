<?php

namespace VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders;

use BackedEnum;
use Barryvdh\DomPDF\Facade\Pdf;
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
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use VentureDrake\LaravelCrm\Mail\SendPurchaseOrder;
use VentureDrake\LaravelCrm\Models\PurchaseOrder;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\LeadDealContactSection;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\LineItemsRepeater;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\MoneyTotalsRow;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\PurchaseOrderDeliverySection;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\SalesDetailsSection;
use VentureDrake\LaravelCrmFilament\Concerns\HasCrmCustomFieldEntries;
use VentureDrake\LaravelCrmFilament\Concerns\HasCrmCustomFields;
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
use VentureDrake\LaravelCrmFilament\Resources\Orders\OrderResource;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\OrganizationResource;
use VentureDrake\LaravelCrmFilament\Resources\People\PersonResource;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use VentureDrake\LaravelCrmFilament\Support\PortalUrl;

class PurchaseOrderResource extends Resource
{
    use HasCrmCustomFieldEntries;
    use HasCrmCustomFields;
    use HasLabels;
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
                        LineItemsRepeater::products('purchase_order_line_id', 'unit_price')->defaultItems(1),
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

                Tables\Columns\TextColumn::make('purchase_order_id')
                    ->label(__('laravel-crm-filament::labels.fields.number'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('reference')
                    ->label(__('laravel-crm-filament::labels.fields.reference'))
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('order.order_id')
                    ->label(__('laravel-crm-filament::labels.fields.order'))
                    ->url(fn ($record) => $record->order
                        ? OrderResource::getUrl('view', ['record' => $record->order])
                        : null)
                    ->color('primary')
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
                    ->toggleable(),

                Tables\Columns\TextColumn::make('total')
                    ->label(__('laravel-crm-filament::labels.money.amount'))
                    ->money(fn ($record) => $record->currency ?: config('laravel-crm.default_currency', 'USD'))
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
                    ->hidden(fn (?PurchaseOrder $record) => $record !== null && $record->xeroPurchaseOrder()->exists())
                    ->hiddenLabel(),
                Actions\DeleteAction::make()
                    ->button()
                    ->requiresConfirmation()
                    ->hidden(fn (?PurchaseOrder $record) => $record !== null && $record->xeroPurchaseOrder()->exists())
                    ->hiddenLabel(),
            ])
            ->toolbarActions([
                static::primaryBulkActionGroup(),
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
                        ->url(fn ($record) => $record?->order
                            ? OrderResource::getUrl('view', ['record' => $record->order])
                            : null),

                    TextEntry::make('issue_date')
                        ->label(__('laravel-crm-filament::labels.money.issue_date'))
                        ->date(),

                    TextEntry::make('delivery_date')
                        ->label(__('laravel-crm-filament::labels.money.delivery_date'))
                        ->date(),

                    TextEntry::make('delivery_type')
                        ->label(__('laravel-crm-filament::labels.sales.delivery_type')),

                    TextEntry::make('total')
                        ->label(__('laravel-crm-filament::labels.money.amount'))
                        ->money(fn ($record) => $record?->currency ?: config('laravel-crm.default_currency', 'USD')),

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

    public static function sendPurchaseOrderActionFactory(): Action
    {
        return Action::make('send')
            ->label(__('laravel-crm-filament::labels.actions.send'))
            ->icon('heroicon-o-paper-airplane')
            ->color('success')
            ->modalHeading('Send purchase order')
            ->modalSubmitActionLabel('Send')
            ->schema(fn (PurchaseOrder $record): array => [
                TextInput::make('to')
                    ->label(__('laravel-crm-filament::labels.campaign.to'))
                    ->email()
                    ->required(),
                TextInput::make('subject')
                    ->required()
                    ->default(fn () => 'Purchase Order ' . $record->purchase_order_id),
                Textarea::make('message')
                    ->rows(8)
                    ->default("Hi,\n\nPlease find the purchase order here: [Online Purchase Order Link]\n\nThanks."),
                Checkbox::make('cc')
                    ->label(__('laravel-crm-filament::labels.campaign.send_me_a_copy')),
            ])
            ->action(function (array $data, PurchaseOrder $record): void {
                static::dispatchPurchaseOrderSend($record, $data);

                Notification::make()
                    ->title('Purchase order sent')
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
            ->url(fn (PurchaseOrder $record): ?string => PortalUrl::for('laravel-crm.portal.purchase-orders.show', $record))
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

    protected static function dispatchPurchaseOrderSend(PurchaseOrder $record, array $data): void
    {
        $signedUrl = PortalUrl::exists('laravel-crm.portal.purchase-orders.show')
            ? URL::temporarySignedRoute('laravel-crm.portal.purchase-orders.show', now()->addDays(14), ['purchaseOrder' => $record])
            : '';

        $pdfPath = static::renderPurchaseOrderPdfToDisk($record);

        Mail::send(new SendPurchaseOrder([
            'to' => $data['to'],
            'subject' => $data['subject'],
            'message' => $data['message'],
            'cc' => ! empty($data['cc']) ? 1 : 0,
            'onlinePurchaseOrderLink' => $signedUrl,
            'pdf' => $pdfPath,
        ]));
    }

    protected static function renderPurchaseOrderPdfToDisk(PurchaseOrder $record): string
    {
        $settings = app('laravel-crm.settings');

        $data = [
            'purchaseOrder' => $record,
            'dateFormat' => $settings->get('date_format', config('laravel-crm.date_format')),
            'fromName' => $settings->get('organization_name'),
            'logo' => $settings->get('logo_file'),
        ];

        $relativeDir = 'laravel-crm/purchaseorder/' . $record->id;
        Storage::makeDirectory($relativeDir);

        $filename = 'purchase-order-' . strtolower((string) ($record->purchase_order_id ?? $record->external_id)) . '.pdf';
        $pdfRelative = 'app/' . $relativeDir . '/' . $filename;

        Pdf::setOption(['fontDir' => public_path('vendor/laravel-crm/fonts')])
            ->loadView('laravel-crm::purchase-orders.pdf', $data)
            ->save(storage_path($pdfRelative));

        return $pdfRelative;
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
