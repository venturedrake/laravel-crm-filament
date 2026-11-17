<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Services\InvoiceService;
use VentureDrake\LaravelCrm\Services\PersonService;
use VentureDrake\LaravelCrmFilament\Concerns\SurfacesServiceValidation;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\InvoiceResource;
use VentureDrake\LaravelCrmFilament\Support\FormPayload;

class CreateInvoice extends CreateRecord
{
    use SurfacesServiceValidation;

    protected static string $resource = InvoiceResource::class;

    /**
     * Core's services already run in a transaction; this one also covers
     * the contact links and custom field values saved around them.
     */
    protected ?bool $hasDatabaseTransactions = true;

    protected function handleRecordCreation(array $data): Model
    {
        $person = isset($data['person_id']) ? Person::find($data['person_id']) : null;
        $organization = isset($data['organization_id']) ? Organization::find($data['organization_id']) : null;

        // Saved together, the person and organization become related
        // contacts of each other, as core 2.5.0 does (#94).
        app(PersonService::class)->linkToOrganization($person, $organization);

        $record = $this->surfacingServiceValidation(
            fn () => app(InvoiceService::class)->create(FormPayload::wrap($data), $person, $organization),
        );

        InvoiceResource::saveCrmCustomFields($data, $record);

        return $record;
    }
}
