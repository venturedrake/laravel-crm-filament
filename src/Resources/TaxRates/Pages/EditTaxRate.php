<?php

namespace VentureDrake\LaravelCrmFilament\Resources\TaxRates\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use VentureDrake\LaravelCrmFilament\Resources\TaxRates\TaxRateResource;

class EditTaxRate extends EditRecord
{
    protected static string $resource = TaxRateResource::class;

    protected function getHeaderActions(): array
    {
        return [TaxRateResource::refuseDeleteWhenInUse(Actions\DeleteAction::make())];
    }
}
