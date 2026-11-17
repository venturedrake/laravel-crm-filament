<?php

namespace VentureDrake\LaravelCrmFilament\Resources\TaxRates\Pages;

use Filament\Actions;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use VentureDrake\LaravelCrmFilament\RelationManagers\TaxRateProductsRelationManager;
use VentureDrake\LaravelCrmFilament\Resources\TaxRates\TaxRateResource;

class ViewTaxRate extends ViewRecord
{
    protected static string $resource = TaxRateResource::class;

    public function getTitle(): string
    {
        return __('laravel-crm-filament::labels.money.tax_rate') . ': ' . $this->record->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            TaxRateResource::backToIndexAction(),
            Actions\EditAction::make()
                ->button()
                ->hiddenLabel()
                ->icon('heroicon-m-pencil-square'),
            TaxRateResource::refuseDeleteWhenInUse(Actions\DeleteAction::make()
                ->button()
                ->hiddenLabel()
                ->icon('heroicon-m-trash')),
        ];
    }

    public function content(Schema $schema): Schema
    {
        // Capture the record now (page has it bound by the time content() runs)
        // so the closures below can read it directly instead of relying on
        // Filament's parameter injection, which was firing with $record=null
        // when nested Section schemas were evaluated in some render phases.
        // See commit 54b6a24 for the recurring bug this pattern avoids.
        $record = $this->record;

        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 2])->schema([
                Section::make(__('laravel-crm-filament::labels.sections.details'))
                    ->schema([
                        TextEntry::make('name')
                            ->label(__('laravel-crm-filament::labels.fields.name'))
                            ->state($record?->name),
                        TextEntry::make('rate')
                            ->label(__('laravel-crm-filament::labels.money.tax_rate'))
                            ->state($record?->rate !== null ? $record->rate . '%' : null),
                        TextEntry::make('tax_type')
                            ->label(__('laravel-crm-filament::labels.fields.type'))
                            ->state($record?->tax_type),
                        TextEntry::make('default')
                            ->label(__('laravel-crm-filament::labels.money.default_tax_rate'))
                            ->badge()
                            ->state($record?->default ? __('laravel-crm-filament::labels.money.default_tax_rate') : null)
                            ->color('success'),
                        TextEntry::make('description')
                            ->label(__('laravel-crm-filament::labels.fields.description'))
                            ->state($record?->description)
                            ->columnSpanFull(),
                    ])
                    ->columnSpan(['lg' => 1]),
                Livewire::make(TaxRateProductsRelationManager::class, [
                    'ownerRecord' => $record,
                    'pageClass' => static::class,
                ])
                    ->key('tax-rate-products-' . $record->getKey())
                    ->columnSpan(['lg' => 1]),
            ]),
        ]);
    }
}
