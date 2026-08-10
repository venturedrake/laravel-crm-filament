<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Xero;

use BackedEnum;
use Filament\Actions;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use VentureDrake\LaravelCrm\Models\XeroItem;
use VentureDrake\LaravelCrmFilament\LaravelCrmPlugin;
use VentureDrake\LaravelCrmFilament\Resources\Xero\Pages\ListXeroItems;
use VentureDrake\LaravelCrmFilament\Resources\Xero\Pages\ViewXeroItem;
use VentureDrake\LaravelCrmFilament\Support\MoneyForm;

class XeroItemResource extends Resource
{
    protected static ?string $model = XeroItem::class;

    protected static ?string $slug = 'xero-items';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-cube';

    protected static ?int $navigationSort = 92;

    public static function getNavigationGroup(): ?string
    {
        return LaravelCrmPlugin::get()->getNavigationGroup() ?? 'Integrations';
    }

    public static function getNavigationLabel(): string
    {
        return __('laravel-crm-filament::labels.xero.items');
    }

    public static function getRecordRouteKeyName(): ?string
    {
        return 'external_id';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('xero_item_details')
                ->heading(__('laravel-crm-filament::labels.xero.item_details'))
                ->columns(2)
                ->schema([
                    TextEntry::make('name')
                        ->label(__('laravel-crm-filament::labels.fields.name')),
                    TextEntry::make('code')
                        ->label(__('laravel-crm-filament::labels.fields.code'))
                        ->copyable(),
                    TextEntry::make('item_id')
                        ->label(__('laravel-crm-filament::labels.xero.item_id'))
                        ->copyable(),
                    TextEntry::make('product.name')
                        ->label(__('laravel-crm-filament::labels.xero.linked_product'))
                        ->placeholder('—'),
                    // Not CrmMoney: xero_items carries no currency column, so
                    // there is nothing to render these in but the CRM default,
                    // which would label a Xero org's AUD prices as USD. They
                    // stay bare amounts — still through MoneyForm, so the /100
                    // lives in the one place.
                    TextEntry::make('purchase_price')
                        ->label(__('laravel-crm-filament::labels.xero.purchase_price'))
                        ->state(fn ($record) => MoneyForm::format($record->purchase_price))
                        ->placeholder('—'),
                    TextEntry::make('sell_price')
                        ->label(__('laravel-crm-filament::labels.xero.sell_price'))
                        ->state(fn ($record) => MoneyForm::format($record->sell_price))
                        ->placeholder('—'),
                    TextEntry::make('quantity_on_hand')
                        ->label(__('laravel-crm-filament::labels.xero.quantity_on_hand'))
                        ->placeholder('—'),
                    TextEntry::make('updated_date')
                        ->label(__('laravel-crm-filament::labels.xero.last_sync'))
                        ->dateTime()
                        ->placeholder('—'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('laravel-crm-filament::labels.fields.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('code')
                    ->label(__('laravel-crm-filament::labels.fields.code'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('product.name')
                    ->label(__('laravel-crm-filament::labels.xero.linked_product'))
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_sold')
                    ->label(__('laravel-crm-filament::labels.xero.is_sold'))
                    ->boolean()
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_purchased')
                    ->label(__('laravel-crm-filament::labels.xero.is_purchased'))
                    ->boolean()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('updated_date')
                    ->label(__('laravel-crm-filament::labels.xero.last_sync'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('updated_date', 'desc')
            ->recordActions([
                Actions\ViewAction::make()
                    ->button()
                    ->hiddenLabel(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListXeroItems::route('/'),
            'view' => ViewXeroItem::route('/{record}'),
        ];
    }
}
