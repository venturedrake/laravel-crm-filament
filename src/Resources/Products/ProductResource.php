<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Products;

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
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\TaxRate;
use VentureDrake\LaravelCrmFilament\Concerns\ExportsCsv;
use VentureDrake\LaravelCrmFilament\Concerns\HasCrmCustomFieldEntries;
use VentureDrake\LaravelCrmFilament\Concerns\HasCrmCustomFields;
use VentureDrake\LaravelCrmFilament\Concerns\HasLabels;
use VentureDrake\LaravelCrmFilament\Concerns\HasPrimaryBulkActions;
use VentureDrake\LaravelCrmFilament\Concerns\UsesExternalIdRouting;
use VentureDrake\LaravelCrmFilament\LaravelCrmPlugin;
use VentureDrake\LaravelCrmFilament\Resources\Products\Pages\CreateProduct;
use VentureDrake\LaravelCrmFilament\Resources\Products\Pages\EditProduct;
use VentureDrake\LaravelCrmFilament\Resources\Products\Pages\ListProducts;
use VentureDrake\LaravelCrmFilament\Resources\Products\Pages\ViewProduct;
use VentureDrake\LaravelCrmFilament\Support\CrmMoney;
use VentureDrake\LaravelCrmFilament\Support\MoneyForm;

class ProductResource extends Resource
{
    use HasCrmCustomFieldEntries;
    use HasCrmCustomFields;
    use HasLabels;
    use HasPrimaryBulkActions;
    use UsesExternalIdRouting;

    protected static ?string $model = Product::class;

    protected static ?string $slug = 'products';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-cube';

    protected static ?int $navigationSort = 55;

    public static function getNavigationGroup(): ?string
    {
        return LaravelCrmPlugin::get()->getNavigationGroup() ?? 'Catalog';
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Product::query()->count();

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
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('code')
                    ->label(__('laravel-crm-filament::labels.money.sku'))
                    ->maxLength(100),
            ]),

            Grid::make(3)->schema([
                Forms\Components\TextInput::make('barcode')
                    ->label(__('laravel-crm-filament::labels.money.barcode'))
                    ->maxLength(100),
                Forms\Components\TextInput::make('product_category')
                    ->maxLength(255),
                Forms\Components\TextInput::make('unit')
                    ->maxLength(50),
            ]),

            Grid::make(3)->schema([
                Forms\Components\TextInput::make('unit_price')
                    ->label(__('laravel-crm-filament::labels.money.unit_price'))
                    ->numeric(),
                Forms\Components\TextInput::make('currency')
                    ->maxLength(3)
                    ->default(config('laravel-crm.default_currency', 'USD')),
                Forms\Components\Select::make('tax_rate_id')
                    ->label(__('laravel-crm-filament::labels.money.tax_rate'))
                    ->options(fn () => TaxRate::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable(),
            ]),

            Grid::make(2)->schema([
                Forms\Components\TextInput::make('purchase_account')
                    ->maxLength(50),
                Forms\Components\TextInput::make('sales_account')
                    ->maxLength(50),
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
        ];

        if ($customFields = static::crmCustomFieldsSection(Product::class)) {
            $components[] = $customFields;
        }

        $components[] = static::variationsSection();

        return $schema->components($components);
    }

    /**
     * Core 2.5.0 product variations. The rows are handed to
     * ProductService::syncVariations() as `variations`: a row with an id
     * updates that variation, one without creates it, and a removed row is
     * soft-deleted, so lines on it keep their label. The row order becomes the
     * display order. Each row has one price, in the product's own currency.
     */
    protected static function variationsSection(): Section
    {
        return Section::make(__('laravel-crm-filament::labels.sections.variations'))
            ->description(__('laravel-crm-filament::labels.money.variation_price_fallback'))
            ->schema([
                Forms\Components\Repeater::make('variations')
                    ->hiddenLabel()
                    ->schema([
                        Forms\Components\Hidden::make('id'),
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Grid::make(4)->schema([
                            Forms\Components\TextInput::make('code')
                                ->label(__('laravel-crm-filament::labels.money.sku'))
                                ->maxLength(255),
                            Forms\Components\TextInput::make('barcode')
                                ->label(__('laravel-crm-filament::labels.money.barcode'))
                                ->maxLength(255),
                            Forms\Components\TextInput::make('unit_price')
                                ->label(__('laravel-crm-filament::labels.money.price'))
                                ->numeric()
                                ->minValue(0),
                            Forms\Components\Toggle::make('active')
                                ->label(__('laravel-crm-filament::labels.fields.active'))
                                ->default(true)
                                ->inline(false),
                        ]),
                    ])
                    ->itemLabel(fn (array $state): ?string => filled($state['name'] ?? null) ? $state['name'] : null)
                    ->addActionLabel(__('laravel-crm-filament::labels.actions.add_variation'))
                    ->defaultItems(0)
                    ->reorderable()
                    ->collapsible(),
            ])
            ->collapsible()
            ->columnSpanFull();
    }

    /**
     * Repeater rows for a product's variations, each priced in $currency
     * (major units), or null where the variation has no price of its own in
     * that currency.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function variationRows(Product $product, ?string $currency): array
    {
        return $product->productVariations()
            ->with('productPrices')
            ->get()
            ->map(fn ($variation) => [
                'id' => $variation->id,
                'name' => $variation->name,
                'code' => $variation->code,
                'barcode' => $variation->barcode,
                'unit_price' => MoneyForm::centsToForm($variation->priceFor($currency)?->unit_price),
                'active' => (bool) $variation->active,
            ])
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'productCategory',
                'taxRate',
                'ownerUser',
                'productPrices',
                'xeroItem',
                'labels',
            ]))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\IconColumn::make('xero_contact_indicator')
                    ->label('')
                    ->state(fn ($record) => $record?->xeroItem !== null)
                    ->boolean()
                    ->visible(fn (): bool => LaravelCrmPlugin::get()->isModuleEnabled('xero')),

                Tables\Columns\TextColumn::make('code')
                    ->label(__('laravel-crm-filament::labels.money.sku'))
                    ->sortable()
                    // A variation's SKU finds its parent product, as core's
                    // products index does from 2.5.0.
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(
                        fn (Builder $query) => $query
                            ->where('code', 'like', "%{$search}%")
                            ->orWhereHas('productVariations', fn (Builder $variations) => $variations->where('code', 'like', "%{$search}%"))
                    ))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('productCategory.name')
                    ->label(__('laravel-crm-filament::labels.fields.category'))
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('unit')
                    ->label(__('laravel-crm-filament::labels.fields.unit'))
                    ->sortable()
                    ->toggleable(),

                CrmMoney::column('price', fn ($record) => static::defaultPrice($record)?->currency)
                    ->label(__('laravel-crm-filament::labels.money.price'))
                    ->state(fn ($record) => static::defaultPrice($record)?->unit_price ?: null),

                Tables\Columns\TextColumn::make('taxRate.name')
                    ->label(__('laravel-crm-filament::labels.money.tax'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('taxRate.rate')
                    ->label(__('laravel-crm-filament::labels.money.tax_rate'))
                    ->numeric()
                    ->suffix('%')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('active')
                    ->boolean()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('ownerUser.name')
                    ->label(__('laravel-crm-filament::labels.fields.owner'))
                    ->placeholder(__('laravel-crm-filament::labels.misc.unallocated'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('laravel-crm-filament::labels.fields.created'))
                    ->since()
                    ->sortable()
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
                static::primaryBulkActionGroup(),
                // Deliberately not wrapped in an Actions\BulkActionGroup: the
                // Product toolbar keeps a single group (asserted by
                // ProductResourceBulkActionsTest).
                ExportsCsv::action(
                    columns: [
                        'Name' => fn ($r) => $r->name,
                        'Code' => fn ($r) => $r->code,
                        'Category' => fn ($r) => optional($r->productCategory)->name,
                        'Unit' => fn ($r) => $r->unit,
                        'Price' => fn ($r) => (static::defaultPrice($r)->unit_price ?? 0) / 100,
                        'Currency' => fn ($r) => static::defaultPrice($r)?->currency,
                        'Active' => fn ($r) => $r->active ? 'Yes' : 'No',
                        'Owner' => fn ($r) => optional($r->ownerUser)->name,
                        'Created' => fn ($r) => $r->created_at,
                    ],
                    filename: 'products',
                ),
            ]);
    }

    /**
     * Product::getDefaultPrice() off the eager-loaded productPrices. Core only
     * learned to read the loaded relation at 2.4.3; before that every call
     * re-queried both the currency setting and the prices, and the price
     * column calls it twice per row.
     */
    protected static function defaultPrice(?Product $record): ?Model
    {
        if ($record === null) {
            return null;
        }

        if (! $record->relationLoaded('productPrices')) {
            return $record->getDefaultPrice();
        }

        $currency = app('laravel-crm.settings')->get('currency');

        return $record->productPrices->firstWhere('currency', filled($currency) ? $currency : 'USD');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('laravel-crm-filament::labels.sections.details'))
                ->schema(fn (?Product $record) => array_merge([
                    TextEntry::make('code')
                        ->label(__('laravel-crm-filament::labels.money.sku')),

                    TextEntry::make('barcode'),

                    TextEntry::make('purchase_account'),

                    TextEntry::make('sales_account'),

                    TextEntry::make('unit'),

                    TextEntry::make('taxRate.name'),

                    TextEntry::make('taxRate.rate')
                        ->suffix('%'),

                    TextEntry::make('productCategory.name'),

                    TextEntry::make('description')
                        ->columnSpanFull(),

                    TextEntry::make('ownerUser.name')
                        ->placeholder('Unallocated'),
                ], $record ? static::crmCustomFieldEntries($record, false) : [])),

            Section::make(__('laravel-crm-filament::labels.sections.custom_fields'))
                ->schema(fn (?Product $record) => $record ? static::crmCustomFieldEntries($record, true) : [])
                ->hidden(function ($record): bool {
                    if (! $record instanceof Product) {
                        return true;
                    }

                    return ! $record->fields()
                        ->whereHas('field', fn ($q) => $q->whereNotNull('field_group_id'))
                        ->exists();
                }),
        ])->columns(1);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'view' => ViewProduct::route('/{record}'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }

    public static function backToIndexAction(): Action
    {
        return Action::make('backToIndex')
            ->label(__('laravel-crm-filament::labels.actions.back_to_products'))
            ->icon('heroicon-o-arrow-left')
            ->color('gray')
            ->url(static::getUrl('index'));
    }
}
