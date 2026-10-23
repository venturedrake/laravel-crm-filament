<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Services\InvoiceService;
use VentureDrake\LaravelCrm\Services\PersonService;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\InvoiceResource;
use VentureDrake\LaravelCrmFilament\Support\FormPayload;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $person = isset($data['person_id']) ? Person::find($data['person_id']) : null;
        $organization = isset($data['organization_id']) ? Organization::find($data['organization_id']) : null;

        // Saved together, the person and organization become related
        // contacts of each other, as core 2.5.0 does (#94).
        app(PersonService::class)->linkToOrganization($person, $organization);

        $record = app(InvoiceService::class)->create(FormPayload::wrap($data), $person, $organization);

        // InvoiceService doesn't persist discount/adjustments — write them
        // directly so the 5-rollup money row reaches the DB.
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

        InvoiceResource::saveCrmCustomFields($data, $record);

        return $record;
    }
}
