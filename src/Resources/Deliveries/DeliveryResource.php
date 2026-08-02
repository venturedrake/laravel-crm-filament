<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Deliveries;

use BackedEnum;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use VentureDrake\LaravelCrm\Models\AddressType;
use VentureDrake\LaravelCrm\Models\Delivery;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\OrderProduct;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\LeadDealContactSection;
use VentureDrake\LaravelCrmFilament\Concerns\HasCrmCustomFields;
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
use VentureDrake\LaravelCrmFilament\Resources\Deliveries\Pages\CreateDelivery;
use VentureDrake\LaravelCrmFilament\Resources\Deliveries\Pages\EditDelivery;
use VentureDrake\LaravelCrmFilament\Resources\Deliveries\Pages\ListDeliveries;
use VentureDrake\LaravelCrmFilament\Resources\Deliveries\Pages\ViewDelivery;
use VentureDrake\LaravelCrmFilament\Resources\Orders\OrderResource;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\OrganizationResource;
use VentureDrake\LaravelCrmFilament\Resources\People\PersonResource;
use VentureDrake\LaravelCrmFilament\Support\PortalUrl;

class DeliveryResource extends Resource
{
    use HasCrmCustomFields;
    use HasLabels;
    use HasPrimaryBulkActions;
    use UsesExternalIdRouting;

    protected static ?string $model = Delivery::class;

    protected static ?string $slug = 'deliveries';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-truck';

    protected static ?int $navigationSort = 54;

    public static function getNavigationGroup(): ?string
    {
        return LaravelCrmPlugin::get()->getNavigationGroup() ?? 'Sales';
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Delivery::query()->whereNull('delivered_on')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        $components = [
            Grid::make(3)->schema([
                Forms\Components\Select::make('order_id')
                    ->label(__('laravel-crm-filament::labels.fields.order'))
                    ->options(fn () => Order::query()->orderByDesc('id')->limit(50)->get()->mapWithKeys(fn ($o) => [$o->id => $o->order_id])->all())
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\DatePicker::make('delivery_expected')
                    ->label(__('laravel-crm-filament::labels.money.expected')),
                Forms\Components\DatePicker::make('delivered_on')
                    ->label(__('laravel-crm-filament::labels.money.delivered_on')),
            ]),

            // Delivery line items: only order_product_id + quantity per service contract.
            Forms\Components\Repeater::make('products')
                ->label(__('laravel-crm-filament::labels.money.items_delivered'))
                ->schema([
                    Forms\Components\Select::make('order_product_id')
                        ->label(__('laravel-crm-filament::labels.money.order_line'))
                        ->options(function (Get $get) {
                            $orderId = $get('../../order_id');
                            if (! $orderId) {
                                return [];
                            }

                            return OrderProduct::query()
                                ->where('order_id', $orderId)
                                ->with('product')
                                ->get()
                                ->mapWithKeys(fn ($op) => [
                                    $op->id => ($op->product?->name ?? 'Line ' . $op->id) . ' (qty ' . $op->quantity . ')',
                                ])
                                ->all();
                        })
                        ->searchable()
                        ->required(),
                    Forms\Components\TextInput::make('quantity')
                        ->numeric()
                        ->default(1)
                        ->minValue(0),
                ])
                ->columns(2)
                ->addActionLabel('Add line')
                ->defaultItems(0)
                ->columnSpanFull(),

            Forms\Components\Repeater::make('addresses')
                ->label(__('laravel-crm-filament::labels.money.delivery_addresses'))
                ->schema([
                    Forms\Components\Hidden::make('id'),
                    Grid::make(2)->schema([
                        Forms\Components\Select::make('address_type_id')
                            ->label(__('laravel-crm-filament::labels.fields.type'))
                            ->options(fn () => AddressType::query()->pluck('name', 'id')),
                        Forms\Components\TextInput::make('name')->maxLength(255),
                    ]),
                    Grid::make(3)->schema([
                        Forms\Components\TextInput::make('line1')->label(__('laravel-crm-filament::labels.contact.line1'))->maxLength(255),
                        Forms\Components\TextInput::make('line2')->label(__('laravel-crm-filament::labels.contact.line2'))->maxLength(255),
                        Forms\Components\TextInput::make('line3')->label(__('laravel-crm-filament::labels.contact.line3'))->maxLength(255),
                    ]),
                    Grid::make(4)->schema([
                        Forms\Components\TextInput::make('city')->maxLength(255),
                        Forms\Components\TextInput::make('state')->maxLength(255),
                        Forms\Components\TextInput::make('code')->label(__('laravel-crm-filament::labels.contact.postal_code'))->maxLength(20),
                        Forms\Components\TextInput::make('country')->maxLength(255),
                    ]),
                    Grid::make(2)->schema([
                        Forms\Components\TextInput::make('contact')->maxLength(255),
                        Forms\Components\TextInput::make('phone')->maxLength(50),
                    ]),
                ])
                ->addActionLabel('Add address')
                ->defaultItems(0)
                ->columnSpanFull(),

            Forms\Components\Select::make('user_owner_id')
                ->label(__('laravel-crm-filament::labels.fields.owner'))
                ->relationship('ownerUser', 'name')
                ->searchable()
                ->preload(),

            static::labelsField(),
        ];

        if ($customFields = static::crmCustomFieldsSection(Delivery::class)) {
            $components[] = $customFields;
        }

        return $schema->components($components);
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

                Tables\Columns\TextColumn::make('delivery_id')
                    ->label(__('laravel-crm-filament::labels.fields.number'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('order.reference')
                    ->label(__('laravel-crm-filament::labels.fields.reference'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('order.order_id')
                    ->label(__('laravel-crm-filament::labels.fields.order'))
                    ->url(fn ($record) => $record->order
                        ? OrderResource::getUrl('view', ['record' => $record->order])
                        : null)
                    ->color('primary')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('order.person.name')
                    ->label(__('laravel-crm-filament::labels.fields.contact'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('order.organization.name')
                    ->label(__('laravel-crm-filament::labels.fields.organization'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('shipping_address')
                    ->label(__('laravel-crm-filament::labels.sales.shipping_address'))
                    ->state(fn (Delivery $record) => static::formatShippingAddress($record))
                    ->limit(60)
                    ->tooltip(fn (Delivery $record) => static::formatShippingAddress($record))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('delivery_expected')
                    ->label(__('laravel-crm-filament::labels.money.expected'))
                    ->date()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('delivered_on')
                    ->label(__('laravel-crm-filament::labels.money.delivered_on'))
                    ->date()
                    ->sortable()
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
            ])
            ->recordActions([
                static::downloadDeliveryPdfActionFactory()
                    ->button()
                    ->hiddenLabel()
                    ->icon('heroicon-m-arrow-down-tray'),
                static::deliveryPortalActionFactory()
                    ->button()
                    ->hiddenLabel()
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->color('gray'),
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

    /**
     * Render the delivery's shipping address as a single comma-separated line.
     * Prefers the address tagged with the shipping AddressType (id 6 in core's
     * seeded fixtures); falls back to the first address attached to the
     * delivery, then to an empty string when none exists.
     */
    protected static function formatShippingAddress(Delivery $record): ?string
    {
        $address = method_exists($record, 'getShippingAddress')
            ? $record->getShippingAddress()
            : null;

        if (! $address) {
            $address = $record->addresses()->first();
        }

        if (! $address) {
            return null;
        }

        $parts = array_filter([
            $address->line1 ?? null,
            $address->city ?? null,
            $address->state ?? null,
            $address->code ?? null,
            $address->country ?? null,
        ]);

        return $parts === [] ? null : implode(', ', $parts);
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
                ->schema([
                    TextEntry::make('created_at')
                        ->label(__('laravel-crm-filament::labels.fields.created'))
                        ->since(),

                    TextEntry::make('delivery_id')
                        ->label(__('laravel-crm-filament::labels.fields.number')),

                    TextEntry::make('order.order_id')
                        ->label(__('laravel-crm-filament::labels.fields.order'))
                        ->url(fn ($record) => $record?->order
                            ? OrderResource::getUrl('view', ['record' => $record->order])
                            : null),

                    TextEntry::make('order.reference')
                        ->label(__('laravel-crm-filament::labels.fields.reference')),

                    TextEntry::make('delivery_expected')
                        ->label(__('laravel-crm-filament::labels.money.expected'))
                        ->date(),

                    TextEntry::make('delivered_on')
                        ->label(__('laravel-crm-filament::labels.money.delivered_on'))
                        ->date(),

                    TextEntry::make('shipping_address')
                        ->label(__('laravel-crm-filament::labels.sales.shipping_address'))
                        ->state(fn ($record) => $record instanceof Delivery ? static::formatShippingAddress($record) : null)
                        ->columnSpanFull(),

                    TextEntry::make('labels.name')
                        ->label(__('laravel-crm-filament::labels.fields.labels'))
                        ->badge(),

                    TextEntry::make('ownerUser.name')
                        ->label(__('laravel-crm-filament::labels.fields.owner'))
                        ->placeholder(__('laravel-crm-filament::labels.misc.unallocated')),
                ]),

            Section::make(__('laravel-crm-filament::labels.sections.contact'))
                ->schema([
                    TextEntry::make('order.person.name')
                        ->label(__('laravel-crm-filament::labels.fields.contact'))
                        ->state(fn ($record) => LeadDealContactSection::personLabel($record?->order?->person))
                        ->url(fn ($record) => $record?->order?->person
                            ? PersonResource::getUrl('view', ['record' => $record->order->person])
                            : null),

                    TextEntry::make('order.organization.name')
                        ->label(__('laravel-crm-filament::labels.fields.organization'))
                        ->url(fn ($record) => $record?->order?->organization
                            ? OrganizationResource::getUrl('view', ['record' => $record->order->organization])
                            : null),
                ]),

            Section::make(__('laravel-crm-filament::labels.sections.custom_fields'))
                ->schema(fn (?Delivery $record) => [])
                ->hidden(fn (): bool => true),
        ])->columns(1);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeliveries::route('/'),
            'create' => CreateDelivery::route('/create'),
            'view' => ViewDelivery::route('/{record}'),
            'edit' => EditDelivery::route('/{record}/edit'),
        ];
    }

    public static function deliveryPortalActionFactory(): Action
    {
        return Action::make('previewPortal')
            ->label(__('laravel-crm-filament::labels.actions.preview_portal'))
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->color('primary')
            ->visible(fn (Delivery $record): bool => PortalUrl::exists('laravel-crm.portal.deliveries.show')
                && $record->external_id !== null)
            ->url(fn (Delivery $record): ?string => PortalUrl::for('laravel-crm.portal.deliveries.show', $record))
            ->openUrlInNewTab();
    }

    public static function downloadDeliveryPdfActionFactory(): Action
    {
        return Action::make('downloadPdf')
            ->label(__('laravel-crm-filament::labels.actions.download_pdf'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->action(function (Delivery $record) {
                $relative = static::renderDeliveryPdfToDisk($record);

                return Response::download(
                    storage_path($relative),
                    'delivery-' . strtolower((string) ($record->delivery_id ?? $record->external_id)) . '.pdf',
                );
            });
    }

    protected static function renderDeliveryPdfToDisk(Delivery $record): string
    {
        $settings = app('laravel-crm.settings');
        $order = $record->order;

        $data = [
            'delivery' => $record,
            'order' => $order,
            'dateFormat' => $settings->get('date_format', config('laravel-crm.date_format')),
            'email' => optional($order?->person)->getPrimaryEmail(),
            'phone' => optional($order?->person)->getPrimaryPhone(),
            'address' => optional($order?->person)->getPrimaryAddress(),
            'organization_address' => optional($order?->organization)->getPrimaryAddress(),
            'fromName' => $settings->get('organization_name'),
            'logo' => $settings->get('logo_file'),
        ];

        $relativeDir = 'laravel-crm/delivery/' . $record->id;
        Storage::makeDirectory($relativeDir);

        $filename = 'delivery-' . strtolower((string) ($record->delivery_id ?? $record->external_id)) . '.pdf';
        $pdfRelative = 'app/' . $relativeDir . '/' . $filename;

        Pdf::setOption(['fontDir' => public_path('vendor/laravel-crm/fonts')])
            ->loadView('laravel-crm::deliveries.pdf', $data)
            ->save(storage_path($pdfRelative));

        return $pdfRelative;
    }

    public static function backToIndexAction(): Action
    {
        return Action::make('backToIndex')
            ->label(__('laravel-crm-filament::labels.actions.back_to_deliveries'))
            ->color('gray')
            ->icon('heroicon-o-arrow-left')
            ->url(static::getUrl('index'));
    }
}
