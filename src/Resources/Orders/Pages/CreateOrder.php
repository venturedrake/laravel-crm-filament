<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Orders\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Services\OrderService;
use VentureDrake\LaravelCrm\Services\PersonService;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\OrderAddressTabs;
use VentureDrake\LaravelCrmFilament\Concerns\SurfacesServiceValidation;
use VentureDrake\LaravelCrmFilament\Resources\Orders\OrderResource;
use VentureDrake\LaravelCrmFilament\Support\FormPayload;

class CreateOrder extends CreateRecord
{
    use SurfacesServiceValidation;

    protected static string $resource = OrderResource::class;

    /**
     * Core's services already run in a transaction; this one also covers
     * the contact links and custom field values saved around them.
     */
    protected ?bool $hasDatabaseTransactions = true;

    protected function handleRecordCreation(array $data): Model
    {
        $person = isset($data['person_id']) ? Person::find($data['person_id']) : null;
        $organization = isset($data['organization_id']) ? Organization::find($data['organization_id']) : null;

        $form = OrderResource::orderForm();

        $data['addresses'] = OrderAddressTabs::fromFormData($data, $form->addressTypes());

        // Saved together, the person and organization become related
        // contacts of each other, as core 2.5.0 does (#94).
        app(PersonService::class)->linkToOrganization($person, $organization);

        $record = $this->surfacingServiceValidation(
            fn () => app(OrderService::class)->create(FormPayload::wrap($data), $person, $organization, form: $form),
        );

        if ($form->shows('custom_fields')) {
            OrderResource::saveCrmCustomFields($data, $record);
        }

        return $record;
    }
}
