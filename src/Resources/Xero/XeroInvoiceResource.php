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
use VentureDrake\LaravelCrm\Models\XeroInvoice;
use VentureDrake\LaravelCrmFilament\LaravelCrmPlugin;
use VentureDrake\LaravelCrmFilament\Resources\Xero\Pages\ListXeroInvoices;
use VentureDrake\LaravelCrmFilament\Resources\Xero\Pages\ViewXeroInvoice;
use VentureDrake\LaravelCrmFilament\Support\MoneyForm;

class XeroInvoiceResource extends Resource
{
    protected static ?string $model = XeroInvoice::class;

    protected static ?string $slug = 'xero-invoices';

    protected static ?string $recordTitleAttribute = 'number';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-document-currency-dollar';

    protected static ?int $navigationSort = 93;

    public static function getNavigationGroup(): ?string
    {
        return LaravelCrmPlugin::get()->getNavigationGroup() ?? 'Integrations';
    }

    public static function getNavigationLabel(): string
    {
        return __('laravel-crm-filament::labels.xero.invoices');
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
            Section::make('xero_invoice_details')
                ->heading(__('laravel-crm-filament::labels.xero.invoice_details'))
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
                    TextEntry::make('invoice.invoice_id')
                        ->label(__('laravel-crm-filament::labels.xero.linked_invoice'))
                        ->placeholder('—'),
                    TextEntry::make('total')
                        ->label(__('laravel-crm-filament::labels.money.total'))
                        ->state(fn ($record) => MoneyForm::format($record->total, '—')),
                    TextEntry::make('amount_due')
                        ->label(__('laravel-crm-filament::labels.xero.amount_due'))
                        ->state(fn ($record) => MoneyForm::format($record->amount_due, '—')),
                    TextEntry::make('amount_paid')
                        ->label(__('laravel-crm-filament::labels.xero.amount_paid'))
                        ->state(fn ($record) => MoneyForm::format($record->amount_paid, '—')),
                    TextEntry::make('currency_code')
                        ->label(__('laravel-crm-filament::labels.fields.currency')),
                    TextEntry::make('issue_date')
                        ->label(__('laravel-crm-filament::labels.xero.issue_date'))
                        ->date()
                        ->placeholder('—'),
                    TextEntry::make('due_date')
                        ->label(__('laravel-crm-filament::labels.xero.due_date'))
                        ->date()
                        ->placeholder('—'),
                    TextEntry::make('fully_paid_at')
                        ->label(__('laravel-crm-filament::labels.xero.fully_paid_at'))
                        ->dateTime()
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
                Tables\Columns\TextColumn::make('amount_due')
                    ->label(__('laravel-crm-filament::labels.xero.amount_due'))
                    ->money(fn ($record) => $record->currency_code ?: config('laravel-crm.default_currency', 'USD'))
                    ->state(fn ($record) => MoneyForm::centsToForm($record->amount_due))
                    ->toggleable(),
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
                    ->options(fn () => XeroInvoice::query()->select('status')->distinct()->whereNotNull('status')->pluck('status', 'status')->all()),
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
            'index' => ListXeroInvoices::route('/'),
            'view' => ViewXeroInvoice::route('/{record}'),
        ];
    }
}
