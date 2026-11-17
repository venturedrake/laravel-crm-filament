<?php

namespace VentureDrake\LaravelCrmFilament\Resources\TaxRates;

use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use VentureDrake\LaravelCrm\Models\TaxRate;
use VentureDrake\LaravelCrmFilament\Resources\TaxRates\Pages\CreateTaxRate;
use VentureDrake\LaravelCrmFilament\Resources\TaxRates\Pages\EditTaxRate;
use VentureDrake\LaravelCrmFilament\Resources\TaxRates\Pages\ListTaxRates;
use VentureDrake\LaravelCrmFilament\Resources\TaxRates\Pages\ViewTaxRate;

class TaxRateResource extends Resource
{
    protected static ?string $model = TaxRate::class;

    protected static ?string $slug = 'tax-rates';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-percent-badge';

    protected static string | \UnitEnum | null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 60;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                // Core 2.7.0 bounds the rate to 0–100 (StoreTaxRateRequest,
                // HasTaxRateCommon).
                Forms\Components\TextInput::make('rate')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->maxValue(100)
                    ->suffix('%'),
                Forms\Components\TextInput::make('tax_type')
                    ->label(__('laravel-crm-filament::labels.fields.type'))
                    ->maxLength(50),
            ]),
            Forms\Components\Toggle::make('default')
                ->label(__('laravel-crm-filament::labels.money.default_tax_rate'))
                ->columnSpanFull(),
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
                Tables\Columns\TextColumn::make('rate')->suffix('%')->sortable(),
                Tables\Columns\IconColumn::make('default')->boolean(),
                Tables\Columns\TextColumn::make('tax_type')->label(__('laravel-crm-filament::labels.fields.type'))->toggleable(),
                Tables\Columns\TextColumn::make('products_count')
                    ->counts('products')
                    ->label(__('laravel-crm-filament::labels.sales.products'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('laravel-crm-filament::labels.fields.created'))
                    ->since()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('laravel-crm-filament::labels.fields.updated'))
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Actions\ViewAction::make()->button()->hiddenLabel(),
                Actions\EditAction::make()->button()->hiddenLabel(),
                static::refuseDeleteWhenInUse(Actions\DeleteAction::make()->button()->hiddenLabel()->requiresConfirmation()),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    static::refuseBulkDeleteWhenInUse(Actions\DeleteBulkAction::make()),
                ]),
            ]);
    }

    /**
     * Core 2.7.0 refuses to delete a tax rate that products still use
     * (TaxRateIndex/TaxRateShow::delete): a product keeps its `tax_rate_id`,
     * so deleting the rate would leave it pointing at a trashed row. Applied
     * to every delete path: table row, view page and edit page.
     */
    public static function refuseDeleteWhenInUse(Actions\DeleteAction $action): Actions\DeleteAction
    {
        return $action->before(function (Actions\DeleteAction $action, TaxRate $record): void {
            if (($count = $record->productsCount()) > 0) {
                static::notifyInUse($count);

                $action->cancel();
            }
        });
    }

    /**
     * The bulk delete refuses the whole selection if any rate in it is in use,
     * rather than deleting some and leaving the user to work out which.
     */
    public static function refuseBulkDeleteWhenInUse(Actions\DeleteBulkAction $action): Actions\DeleteBulkAction
    {
        return $action->before(function (Actions\DeleteBulkAction $action, iterable $records): void {
            /** @var TaxRate $record */
            foreach ($records as $record) {
                if (($count = $record->productsCount()) > 0) {
                    static::notifyInUse($count);

                    $action->cancel();
                }
            }
        });
    }

    protected static function notifyInUse(int $count): void
    {
        Notification::make()
            ->danger()
            ->title(ucfirst(__('laravel-crm::lang.cannot_delete_tax_rate_in_use', ['count' => $count])))
            ->send();
    }

    public static function backToIndexAction(): Actions\Action
    {
        return Actions\Action::make('backToIndex')
            ->label(__('laravel-crm-filament::labels.actions.back_to_tax_rates'))
            ->icon('heroicon-o-arrow-left')
            ->color('gray')
            ->url(static::getUrl('index'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTaxRates::route('/'),
            'create' => CreateTaxRate::route('/create'),
            'view' => ViewTaxRate::route('/{record}'),
            'edit' => EditTaxRate::route('/{record}/edit'),
        ];
    }
}
