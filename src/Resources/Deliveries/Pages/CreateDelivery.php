<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Deliveries\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrm\Services\DeliveryService;
use VentureDrake\LaravelCrm\Support\DeliveryOptions;
use VentureDrake\LaravelCrmFilament\Resources\Deliveries\DeliveryResource;
use VentureDrake\LaravelCrmFilament\Support\FormPayload;

class CreateDelivery extends CreateRecord
{
    protected static string $resource = DeliveryResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        // With "Delivered on" switched off the field is not on the form, so
        // core does not write the column.
        return app(DeliveryService::class)->create(
            FormPayload::wrap($data),
            withDeliveredOn: DeliveryOptions::showsDeliveredOn(),
        );
    }
}
