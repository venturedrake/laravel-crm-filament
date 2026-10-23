<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Products\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;
use VentureDrake\LaravelCrmFilament\RelationManagers\ProductPricesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\ProductVariationsRelationManager;
use VentureDrake\LaravelCrmFilament\Resources\Products\ProductResource;

class ViewProduct extends ViewRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ProductResource::backToIndexAction(),
            Actions\EditAction::make()
                ->button()
                ->hiddenLabel()
                ->icon('heroicon-m-pencil-square'),
            Actions\DeleteAction::make()
                ->button()
                ->hiddenLabel()
                ->icon('heroicon-m-trash'),
        ];
    }

    public function content(Schema $schema): Schema
    {
        $ownerData = [
            'ownerRecord' => $this->getRecord(),
            'pageClass' => static::class,
        ];

        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 2])->schema([
                $this->getInfolistContentComponent()->columnSpan(['lg' => 1]),
                Livewire::make(ProductPricesRelationManager::class, $ownerData)
                    ->key('product-prices-' . $this->getRecord()->getKey())
                    ->columnSpan(['lg' => 1]),
            ]),
            Livewire::make(ProductVariationsRelationManager::class, $ownerData)
                ->key('product-variations-' . $this->getRecord()->getKey()),
        ]);
    }
}
