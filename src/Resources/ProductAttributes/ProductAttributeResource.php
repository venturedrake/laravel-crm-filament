<?php

namespace VentureDrake\LaravelCrmFilament\Resources\ProductAttributes;

use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Schema as DbSchema;
use VentureDrake\LaravelCrm\Models\ProductAttribute;
use VentureDrake\LaravelCrmFilament\Concerns\GuardsPoliciedResource;
use VentureDrake\LaravelCrmFilament\Resources\ProductAttributes\Pages\CreateProductAttribute;
use VentureDrake\LaravelCrmFilament\Resources\ProductAttributes\Pages\EditProductAttribute;
use VentureDrake\LaravelCrmFilament\Resources\ProductAttributes\Pages\ListProductAttributes;

class ProductAttributeResource extends Resource
{
    use GuardsPoliciedResource;

    protected static ?string $model = ProductAttribute::class;

    protected static ?string $slug = 'product-attributes';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static string | \UnitEnum | null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 55;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(255),
            Forms\Components\Textarea::make('description')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('description')->limit(60)->toggleable(),
            ])
            ->defaultSort('name')
            ->recordActions([
                Actions\EditAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductAttributes::route('/'),
            'create' => CreateProductAttribute::route('/create'),
            'edit' => EditProductAttribute::route('/{record}/edit'),
        ];
    }

    public static function canGloballySearch(): bool
    {
        // Core `laravel-crm` doesn't ship a `crm_product_attributes` migration;
        // if the host hasn't created the table, Filament's global search would
        // otherwise 500 on a POST to /livewire when searching. Return false
        // when the table is missing so this resource is silently skipped.
        if (! DbSchema::hasTable((string) config('laravel-crm.db_table_prefix', 'crm_') . 'product_attributes')) {
            return false;
        }

        return parent::canGloballySearch();
    }
}
