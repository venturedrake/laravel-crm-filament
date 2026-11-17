<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Deal;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Resources\Deals\Pages\ViewDeal;
use VentureDrake\LaravelCrmFilament\Resources\Leads\Pages\ViewLead;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\OrganizationResource;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\Pages\EditOrganization;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\Pages\ListOrganizations;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\Pages\ViewOrganization;
use VentureDrake\LaravelCrmFilament\Resources\People\Pages\EditPerson;
use VentureDrake\LaravelCrmFilament\Resources\People\Pages\ListPeople;
use VentureDrake\LaravelCrmFilament\Resources\People\Pages\ViewPerson;
use VentureDrake\LaravelCrmFilament\Resources\People\PersonResource;

use function Pest\Livewire\livewire;

/**
 * Core 2.7.0 (#102): deleting a person or organization warns about the
 * records still linked to it (deleteWarning()). Deletion still goes ahead,
 * and the linked leads and deals show the contact as "Name (deleted)" with
 * no link, through personWithTrashed() / organizationWithTrashed().
 */
beforeEach(function () {
    actingAsCrmAdmin();

    Setting::updateOrCreate(['name' => 'currency'], ['external_id' => (string) Str::uuid(), 'value' => 'USD']);
    app('laravel-crm.settings')->forgetCache();
});

function deleteWarningPerson(string $first = 'Ada', string $last = 'Lovelace'): Person
{
    return Person::create(['external_id' => (string) Str::uuid(), 'first_name' => $first, 'last_name' => $last]);
}

/**
 * The description of the delete dialog the page has open. Filament renders
 * action modals outside the page's own HTML, so it is read off the action.
 */
function mountedDeleteDescription(mixed $component): ?string
{
    $description = $component->instance()->getMountedAction()?->getModalDescription();

    return $description === null ? null : (string) $description;
}

function deleteWarningText(string $model, string $records): string
{
    return ucfirst(__('laravel-crm::lang.delete_linked_records_warning', [
        'model' => __('laravel-crm::lang.' . $model),
        'records' => $records,
    ]));
}

it('warns about each kind of linked record in a person row delete dialog', function () {
    $person = deleteWarningPerson();
    Deal::create(['title' => 'Fit-out', 'person_id' => $person->id, 'currency' => 'USD']);
    Deal::create(['title' => 'Repaint', 'person_id' => $person->id, 'currency' => 'USD']);
    Quote::create(['title' => 'Fit-out', 'currency' => 'USD', 'person_id' => $person->id]);

    $warning = deleteWarningText('person', '2 deals and 1 quote');

    expect($person->deleteWarning())->toBe($warning);

    $list = livewire(ListPeople::class)
        ->mountAction(TestAction::make('delete')->table($person));

    expect(mountedDeleteDescription($list))->toBe($warning);
});

it('keeps the usual confirmation for a person with nothing linked', function () {
    $person = deleteWarningPerson();

    $list = livewire(ListPeople::class)
        ->mountAction(TestAction::make('delete')->table($person));

    expect(mountedDeleteDescription($list))->toBe(__('filament-actions::modal.confirmation'));
});

it('counts the linked records in the list query rather than per row', function () {
    $person = deleteWarningPerson();
    Deal::create(['title' => 'Fit-out', 'person_id' => $person->id, 'currency' => 'USD']);

    $row = PersonResource::withLinkedRecordCounts(Person::query())->whereKey($person->id)->sole();

    expect($row->getAttributes())->toHaveKeys(['leads_count', 'deals_count', 'quotes_count', 'orders_count', 'invoices_count', 'purchase_orders_count'])
        ->and((int) $row->deals_count)->toBe(1)
        ->and($row->deleteWarning())->toBe(deleteWarningText('person', '1 deal'));
});

it('warns about an organization\'s people as well as its documents', function () {
    $organization = Organization::create(['external_id' => (string) Str::uuid(), 'name' => 'Analytical Engines']);
    Person::create(['external_id' => (string) Str::uuid(), 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'organization_id' => $organization->id]);
    Person::create(['external_id' => (string) Str::uuid(), 'first_name' => 'Charles', 'last_name' => 'Babbage', 'organization_id' => $organization->id]);
    Deal::create(['title' => 'Difference engine', 'organization_id' => $organization->id, 'currency' => 'USD']);

    $warning = deleteWarningText('organization', '2 people and 1 deal');

    $list = livewire(ListOrganizations::class)
        ->mountAction(TestAction::make('delete')->table($organization));

    expect(mountedDeleteDescription($list))->toBe($warning);

    $row = OrganizationResource::withLinkedRecordCounts(Organization::query())->whereKey($organization->id)->sole();
    expect($row->deleteWarning())->toBe($warning);
});

it('shows the warning on the person and organization view and edit pages', function () {
    $person = deleteWarningPerson();
    Deal::create(['title' => 'Fit-out', 'person_id' => $person->id, 'currency' => 'USD']);
    $organization = Organization::create(['external_id' => (string) Str::uuid(), 'name' => 'Analytical Engines']);
    Deal::create(['title' => 'Engine', 'organization_id' => $organization->id, 'currency' => 'USD']);

    foreach ([
        [ViewPerson::class, $person, 'person'],
        [EditPerson::class, $person, 'person'],
        [ViewOrganization::class, $organization, 'organization'],
        [EditOrganization::class, $organization, 'organization'],
    ] as [$page, $record, $model]) {
        $action = livewire($page, ['record' => $record->external_id])->instance()->getAction('delete');

        expect($action->getModalDescription())->toBe(deleteWarningText($model, '1 deal'));
    }
});

it('warns in bulk with the one warning, or a count when several are linked', function () {
    $linked = deleteWarningPerson();
    $alsoLinked = deleteWarningPerson('Charles', 'Babbage');
    $unlinked = deleteWarningPerson('Mary', 'Somerville');
    Deal::create(['title' => 'Fit-out', 'person_id' => $linked->id, 'currency' => 'USD']);
    Lead::create(['external_id' => (string) Str::uuid(), 'title' => 'Engine', 'person_id' => $alsoLinked->id, 'currency' => 'USD']);

    $one = livewire(ListPeople::class)
        ->selectTableRecords([$linked->getKey(), $unlinked->getKey()])
        ->mountAction(TestAction::make('delete')->table()->bulk());

    expect(mountedDeleteDescription($one))->toBe(deleteWarningText('person', '1 deal'));

    $several = livewire(ListPeople::class)
        ->selectTableRecords([$linked->getKey(), $alsoLinked->getKey(), $unlinked->getKey()])
        ->mountAction(TestAction::make('delete')->table()->bulk());

    expect(mountedDeleteDescription($several))
        ->toBe(__('laravel-crm-filament::labels.contact.bulk_delete_linked_warning', ['count' => 2]));
});

it('still deletes, and the deal shows the person and organization as deleted with no link', function () {
    $person = deleteWarningPerson();
    $organization = Organization::create(['external_id' => (string) Str::uuid(), 'name' => 'Analytical Engines']);
    $deal = Deal::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Fit-out',
        'person_id' => $person->id,
        'organization_id' => $organization->id,
        'currency' => 'USD',
    ]);

    livewire(ListPeople::class)
        ->callAction(TestAction::make('delete')->table($person));
    $organization->delete();

    expect($person->fresh()->trashed())->toBeTrue()
        ->and($deal->fresh()->person_id)->toBe($person->id);

    $deleted = ' (' . __('laravel-crm::lang.deleted') . ')';

    livewire(ViewDeal::class, ['record' => $deal->fresh()->external_id])
        ->assertSee('Ada Lovelace' . $deleted)
        ->assertSee('Analytical Engines' . $deleted)
        ->assertDontSee(PersonResource::getUrl('view', ['record' => $person]), false)
        ->assertDontSee(OrganizationResource::getUrl('view', ['record' => $organization]), false);
});

it('shows a deleted person on the lead too, and links a live one', function () {
    $live = deleteWarningPerson('Charles', 'Babbage');
    $lead = Lead::create(['external_id' => (string) Str::uuid(), 'title' => 'Engine', 'person_id' => $live->id, 'currency' => 'USD']);

    livewire(ViewLead::class, ['record' => $lead->external_id])
        ->assertSee('Charles Babbage')
        ->assertDontSee('Charles Babbage (' . __('laravel-crm::lang.deleted') . ')')
        ->assertSee(PersonResource::getUrl('view', ['record' => $live]), false);

    $live->delete();

    livewire(ViewLead::class, ['record' => $lead->external_id])
        ->assertSee('Charles Babbage (' . __('laravel-crm::lang.deleted') . ')')
        ->assertDontSee(PersonResource::getUrl('view', ['record' => $live]), false);
});
