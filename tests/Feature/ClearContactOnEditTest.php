<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\EditInvoice;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\EditOrder;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\EditQuote;

/**
 * Clearing the Contact or Organization select on an edit form has to unlink
 * it. The quote, order, invoice and purchase-order pages read an empty select
 * as "not submitted" and fell back to the record's current link, so the save
 * quietly kept it — the same symptom core 2.4.7 fixed in its own forms, by a
 * different route. Leads and deals already passed the null through.
 */
beforeEach(function () {
    actingAsCrmAdmin();
});

function updateThroughPage(string $page, Model $record, array $data): Model
{
    $method = new ReflectionMethod($page, 'handleRecordUpdate');

    return $method->invoke(new $page, $record, $data);
}

it('unlinks a cleared contact and organization on a quote', function () {
    $person = Person::create(['external_id' => (string) Str::uuid(), 'first_name' => 'Paul', 'last_name' => 'Clark']);
    $organization = Organization::create(['external_id' => (string) Str::uuid(), 'name' => 'Acme']);

    $quote = Quote::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Fit-out',
        'person_id' => $person->id,
        'organization_id' => $organization->id,
    ]);

    $updated = updateThroughPage(EditQuote::class, $quote, [
        'title' => 'Fit-out',
        'currency' => 'USD',
        'person_id' => null,
        'organization_id' => null,
    ]);

    expect($updated->person_id)->toBeNull()
        ->and($updated->organization_id)->toBeNull();
});

it('keeps the current link when the form did not submit the field at all', function () {
    $person = Person::create(['external_id' => (string) Str::uuid(), 'first_name' => 'Paul']);

    $quote = Quote::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Fit-out',
        'person_id' => $person->id,
    ]);

    $updated = updateThroughPage(EditQuote::class, $quote, ['title' => 'Fit-out', 'currency' => 'USD']);

    expect($updated->person_id)->toBe($person->id);
});

it('treats a submitted null as an unlink on every sales document edit page', function (string $page) {
    $source = file_get_contents((new ReflectionClass($page))->getFileName());

    expect($source)
        ->toContain("array_key_exists('person_id', \$data)")
        ->toContain("array_key_exists('organization_id', \$data)")
        ->not->toContain("isset(\$data['person_id'])");
})->with([EditQuote::class, EditOrder::class, EditInvoice::class, EditPurchaseOrder::class]);
