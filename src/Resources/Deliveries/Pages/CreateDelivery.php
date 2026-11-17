<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Deliveries\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrm\Services\DeliveryService;
use VentureDrake\LaravelCrm\Support\DeliveryOptions;
use VentureDrake\LaravelCrmFilament\Concerns\SurfacesServiceValidation;
use VentureDrake\LaravelCrmFilament\Resources\Deliveries\DeliveryResource;
use VentureDrake\LaravelCrmFilament\Support\FormPayload;

class CreateDelivery extends CreateRecord
{
    use SurfacesServiceValidation;

    protected static string $resource = DeliveryResource::class;

    /**
     * Core's services already run in a transaction; this one also covers
     * the contact links and custom field values saved around them.
     */
    protected ?bool $hasDatabaseTransactions = true;

    protected function handleRecordCreation(array $data): Model
    {
        // With "Delivered on" switched off the field is not on the form, so
        // core does not write the column.
        return $this->surfacingServiceValidation(
            fn () => app(DeliveryService::class)->create(
                FormPayload::wrap($data),
                withDeliveredOn: DeliveryOptions::showsDeliveredOn(),
            ),
        );
    }
}
