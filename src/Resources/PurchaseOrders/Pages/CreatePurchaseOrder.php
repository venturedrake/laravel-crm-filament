<?php

namespace VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Services\PersonService;
use VentureDrake\LaravelCrm\Services\PurchaseOrderService;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\PurchaseOrderResource;
use VentureDrake\LaravelCrmFilament\Support\FormPayload;

class CreatePurchaseOrder extends CreateRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $person = isset($data['person_id']) ? Person::find($data['person_id']) : null;
        $organization = isset($data['organization_id']) ? Organization::find($data['organization_id']) : null;

        // Saved together, the person and organization become related
        // contacts of each other, as core 2.5.0 does (#94).
        app(PersonService::class)->linkToOrganization($person, $organization);

        $record = app(PurchaseOrderService::class)->create(FormPayload::wrap($data), $person, $organization);

        // Persist discount + adjustments (the service doesn't write them).
        $extras = [];
        if (isset($data['discount'])) {
            $extras['discount'] = (int) round(((float) $data['discount']) * 100);
        }
        if (isset($data['adjustment'])) {
            $extras['adjustments'] = (int) round(((float) $data['adjustment']) * 100);
        }
        if ($extras !== []) {
            $record->forceFill($extras)->save();
        }

        PurchaseOrderResource::saveCrmCustomFields($data, $record);

        return $record;
    }
}
