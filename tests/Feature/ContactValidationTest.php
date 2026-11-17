<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Email;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\Phone;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Concerns\ContactFieldsSchema;
use VentureDrake\LaravelCrmFilament\Resources\Leads\Pages\CreateLead;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\Pages\CreateOrganization;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\Pages\EditOrganization;
use VentureDrake\LaravelCrmFilament\Resources\People\Pages\CreatePerson;
use VentureDrake\LaravelCrmFilament\Resources\People\Pages\EditPerson;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\CreateQuote;

use function Pest\Livewire\livewire;

/**
 * Core 2.7.0 checks contact details with its own rules: ContactEmail
 * (`email:rfc`, no DNS) and PhoneNumber (lenient, six digits, optional
 * extension). Values already saved on the record pass as they are, so a
 * legacy value doesn't block an unrelated edit. Type selects default to
 * work (email) / mobile (phone) and are required.
 */
beforeEach(function () {
    actingAsCrmAdmin();

    Setting::updateOrCreate(['name' => 'currency'], ['external_id' => (string) Str::uuid(), 'value' => 'USD']);
    app('laravel-crm.settings')->forgetCache();
});

function contactValidationPerson(array $phones = [], array $emails = []): Person
{
    $person = Person::create([
        'external_id' => (string) Str::uuid(),
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
    ]);

    foreach ($phones as $number => $type) {
        $person->phones()->create(['external_id' => (string) Str::uuid(), 'number' => $number, 'type' => $type, 'primary' => true]);
    }

    foreach ($emails as $address => $type) {
        $person->emails()->create(['external_id' => (string) Str::uuid(), 'address' => $address, 'type' => $type, 'primary' => true]);
    }

    return $person->fresh();
}

/**
 * The key Filament gave the first repeater item after a fill.
 */
function contactValidationFirstKey(mixed $component, string $repeater): string
{
    return (string) array_key_first($component->get("data.{$repeater}") ?? []);
}

describe('the person form', function () {
    it('refuses a bad email address', function (string $address) {
        livewire(CreatePerson::class)
            ->fillForm([
                'first_name' => 'Ada',
                'emails' => [['address' => $address, 'type' => 'work', 'primary' => true]],
            ])
            ->call('create')
            ->assertHasFormErrors(['emails.0.address']);

        expect(Email::query()->count())->toBe(0);
    })->with(['not-an-email', 'ada@', 'ada lovelace@example.com']);

    it('refuses a bad phone number', function (string $number) {
        livewire(CreatePerson::class)
            ->fillForm([
                'first_name' => 'Ada',
                'phones' => [['number' => $number, 'type' => 'mobile', 'primary' => true]],
            ])
            ->call('create')
            ->assertHasFormErrors(['phones.0.number']);

        expect(Phone::query()->count())->toBe(0);
    })->with(['12345', 'call reception', '555-CALL-NOW']);

    it('takes the numbers and addresses core takes, extensions included', function () {
        livewire(CreatePerson::class)
            ->fillForm([
                'first_name' => 'Ada',
                'phones' => [['number' => '+61 2 9999 9999 ext 12', 'type' => 'work', 'primary' => true]],
                'emails' => [['address' => 'ada@example.com', 'type' => 'work', 'primary' => true]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Phone::query()->value('number'))->toBe('+61 2 9999 9999 ext 12')
            ->and(Email::query()->value('address'))->toBe('ada@example.com');
    });

    it('requires a type for each phone and email', function () {
        livewire(CreatePerson::class)
            ->fillForm([
                'first_name' => 'Ada',
                'phones' => [['number' => '0412 345 678', 'type' => null, 'primary' => true]],
                'emails' => [['address' => 'ada@example.com', 'type' => null, 'primary' => true]],
            ])
            ->call('create')
            ->assertHasFormErrors(['phones.0.type' => 'required', 'emails.0.type' => 'required']);
    });

    it('lets a saved legacy number and address through an unrelated edit', function () {
        $person = contactValidationPerson(['call reception' => 'work'], ['not-an-email' => 'work']);

        livewire(EditPerson::class, ['record' => $person->external_id])
            ->fillForm(['last_name' => 'Byron'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($person->fresh()->last_name)->toBe('Byron')
            ->and($person->phones()->value('number'))->toBe('call reception')
            ->and($person->emails()->value('address'))->toBe('not-an-email');
    });

    it('still checks a number or address changed on edit', function () {
        $person = contactValidationPerson(['0412 345 678' => 'mobile'], ['ada@example.com' => 'work']);

        $component = livewire(EditPerson::class, ['record' => $person->external_id]);
        $phoneKey = contactValidationFirstKey($component, 'phones');
        $emailKey = contactValidationFirstKey($component, 'emails');

        $component
            ->set("data.phones.{$phoneKey}.number", 'call reception')
            ->set("data.emails.{$emailKey}.address", 'not-an-email')
            ->call('save')
            ->assertHasFormErrors(["phones.{$phoneKey}.number", "emails.{$emailKey}.address"]);
    });

    it('opens a phone or email saved without a type as mobile / work', function () {
        $person = contactValidationPerson(['0412 345 678' => null], ['ada@example.com' => null]);

        $component = livewire(EditPerson::class, ['record' => $person->external_id]);

        expect(collect($component->get('data.phones'))->first()['type'])->toBe('mobile')
            ->and(collect($component->get('data.emails'))->first()['type'])->toBe('work');
    });
});

describe('the organization form', function () {
    it('refuses a bad email address and phone number', function () {
        livewire(CreateOrganization::class)
            ->fillForm([
                'name' => 'Analytical Engines',
                'phones' => [['number' => '123', 'type' => 'work', 'primary' => true]],
                'emails' => [['address' => 'nope', 'type' => 'work', 'primary' => true]],
            ])
            ->call('create')
            ->assertHasFormErrors(['phones.0.number', 'emails.0.address']);

        expect(Organization::query()->count())->toBe(0);
    });

    it('lets a saved legacy number through an unrelated edit and fills a missing type', function () {
        $organization = Organization::create(['external_id' => (string) Str::uuid(), 'name' => 'Analytical Engines']);
        $organization->phones()->create(['external_id' => (string) Str::uuid(), 'number' => 'ask for Ada', 'type' => null, 'primary' => true]);

        $component = livewire(EditOrganization::class, ['record' => $organization->external_id]);

        expect(collect($component->get('data.phones'))->first()['type'])->toBe('mobile');

        $component
            ->fillForm(['name' => 'Difference Engines'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($organization->fresh()->name)->toBe('Difference Engines');
    });
});

describe('the lead form person modal', function () {
    it('refuses a bad email or phone for the new person', function () {
        livewire(CreateLead::class)
            ->callAction(
                TestAction::make('createOption')->schemaComponent('person_id'),
                ['first_name' => 'Ada', 'email' => 'not-an-email', 'phone' => '12'],
            )
            ->assertHasActionErrors(['email', 'phone']);

        expect(Person::query()->count())->toBe(0);
    });

    it('creates the person with a good email and phone, typed work / mobile by default', function () {
        livewire(CreateLead::class)
            ->callAction(
                TestAction::make('createOption')->schemaComponent('person_id'),
                ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.com', 'phone' => '0412 345 678'],
            )
            ->assertHasNoActionErrors();

        $person = Person::query()->sole();

        expect($person->emails()->value('type'))->toBe('work')
            ->and($person->phones()->value('type'))->toBe('mobile');
    });
});

it('refuses a bad email in the document form person modal', function () {
    livewire(CreateQuote::class)
        ->callAction(
            TestAction::make('createOption')->schemaComponent('person_id'),
            ['first_name' => 'Ada', 'email' => 'not-an-email', 'phone' => '12'],
        )
        ->assertHasActionErrors(['email', 'phone']);

    expect(Person::query()->count())->toBe(0);
});

it('defaults the type selects to work for email and mobile for phone', function () {
    expect(ContactFieldsSchema::emailTypeSelect()->getDefaultState())->toBe('work')
        ->and(ContactFieldsSchema::phoneTypeSelect()->getDefaultState())->toBe('mobile');
});
