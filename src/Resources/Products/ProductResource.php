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

        return $schema->components($components);
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
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('productCategory.name')
                    ->label(__('laravel-crm-filament::labels.fields.category'))
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('unit')
                    ->label(__('laravel-crm-filament::labels.fields.unit'))
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('price')
                    ->label(__('laravel-crm-filament::labels.money.price'))
                    ->state(fn ($record) => optional($record->getDefaultPrice())->unit_price
                        ? optional($record->getDefaultPrice())->unit_price / 100
                        : null)
                    ->money(fn ($record) => $record?->getDefaultPrice()?->currency ?: config('laravel-crm.default_currency', 'USD')),

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
                        'Price' => fn ($r) => (optional($r->getDefaultPrice())->unit_price ?? 0) / 100,
                        'Currency' => fn ($r) => optional($r->getDefaultPrice())->currency,
                        'Active' => fn ($r) => $r->active ? 'Yes' : 'No',
                        'Owner' => fn ($r) => optional($r->ownerUser)->name,
                        'Created' => fn ($r) => $r->created_at,
                    ],
                    filename: 'products',
                ),
            ]);
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
