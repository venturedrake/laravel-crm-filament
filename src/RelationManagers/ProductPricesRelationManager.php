<?php

namespace VentureDrake\LaravelCrmFilament\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrmFilament\Support\CrmMoney;

class ProductPricesRelationManager extends RelationManager
{
    protected static string $relationship = 'productPrices';

    protected static ?string $title = 'Prices';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('laravel-crm-filament::labels.sections.prices');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                CrmMoney::column('unit_price')
                    ->label(__('laravel-crm-filament::labels.money.unit_price')),
                Tables\Columns\TextColumn::make('currency')
                    ->label(__('laravel-crm-filament::labels.fields.currency')),
            ])
            ->defaultSort('id', 'asc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
