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
use VentureDrake\LaravelCrm\Models\XeroPurchaseOrder;
use VentureDrake\LaravelCrmFilament\LaravelCrmPlugin;
use VentureDrake\LaravelCrmFilament\Resources\Xero\Pages\ListXeroPurchaseOrders;
use VentureDrake\LaravelCrmFilament\Resources\Xero\Pages\ViewXeroPurchaseOrder;
use VentureDrake\LaravelCrmFilament\Support\MoneyForm;

class XeroPurchaseOrderResource extends Resource
{
    protected static ?string $model = XeroPurchaseOrder::class;

    protected static ?string $slug = 'xero-purchase-orders';

    protected static ?string $recordTitleAttribute = 'number';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 94;

    public static function getNavigationGroup(): ?string
    {
        return LaravelCrmPlugin::get()->getNavigationGroup() ?? 'Integrations';
    }

    public static function getNavigationLabel(): string
    {
        return __('laravel-crm-filament::labels.xero.purchase_orders');
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
            Section::make('xero_purchase_order_details')
                ->heading(__('laravel-crm-filament::labels.xero.purchase_order_details'))
                ->columns(3)
                ->schema([
                    TextEntry::make('number')
                        ->label(__('laravel-crm-filament::labels.fields.number')),
                    TextEntry::make('reference')
                        ->label(__('laravel-crm-filament::labels.fields.reference'))
                        ->placeholder('—'),
                    TextEntry::make('status')
                        ->label(__('laravel-crm-filament::labels.fields.status'))
                        ->badge(),
                    TextEntry::make('xero_id')
                        ->label(__('laravel-crm-filament::labels.xero.xero_id'))
                        ->copyable(),
                    TextEntry::make('xero_type')
                        ->label(__('laravel-crm-filament::labels.xero.xero_type')),
                    TextEntry::make('purchaseOrder.purchase_order_id')
                        ->label(__('laravel-crm-filament::labels.xero.linked_purchase_order'))
                        ->placeholder('—'),
                    TextEntry::make('total')
                        ->label(__('laravel-crm-filament::labels.money.total'))
                        ->state(fn ($record) => MoneyForm::format($record->total, '—')),
                    TextEntry::make('currency_code')
                        ->label(__('laravel-crm-filament::labels.fields.currency')),
                    TextEntry::make('issue_date')
                        ->label(__('laravel-crm-filament::labels.xero.issue_date'))
                        ->date()
                        ->placeholder('—'),
                    TextEntry::make('delivery_date')
                        ->label(__('laravel-crm-filament::labels.xero.delivery_date'))
                        ->date()
                        ->placeholder('—'),
                    TextEntry::make('xero_updated_at')
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
                Tables\Columns\TextColumn::make('number')
                    ->label(__('laravel-crm-filament::labels.fields.number'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('reference')
                    ->label(__('laravel-crm-filament::labels.fields.reference'))
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('laravel-crm-filament::labels.fields.status'))
                    ->badge()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('total')
                    ->label(__('laravel-crm-filament::labels.money.total'))
                    ->money(fn ($record) => $record->currency_code ?: config('laravel-crm.default_currency', 'USD'))
                    ->state(fn ($record) => MoneyForm::centsToForm($record->total))
                    ->sortable(),
                Tables\Columns\TextColumn::make('xero_updated_at')
                    ->label(__('laravel-crm-filament::labels.xero.last_sync'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('xero_updated_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('laravel-crm-filament::labels.fields.status'))
                    ->options(fn () => XeroPurchaseOrder::query()->select('status')->distinct()->whereNotNull('status')->pluck('status', 'status')->all()),
            ])
            ->recordActions([
                Actions\ViewAction::make()
                    ->button()
                    ->hiddenLabel(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListXeroPurchaseOrders::route('/'),
            'view' => ViewXeroPurchaseOrder::route('/{record}'),
        ];
    }
}
