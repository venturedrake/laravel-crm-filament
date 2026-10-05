<?php

use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrmFilament\Support\EncryptedContactSearch;

/**
 * Person and organization names are ciphertext with encrypt_db_fields on, so
 * SQL LIKE finds nothing and pluck('name') returns the ciphertext. Core 2.4.6
 * fixed its own autocompletes by matching decrypted values in PHP; these pin
 * the panel's equivalent.
 */
beforeEach(function () {
    config(['laravel-crm.encrypt_db_fields' => true]);

    $this->ada = Person::create(['external_id' => (string) Str::uuid(), 'first_name' => 'Ada', 'last_name' => 'Lovelace']);
    $this->grace = Person::create(['external_id' => (string) Str::uuid(), 'first_name' => 'Grace', 'last_name' => 'Hopper', 'maiden_name' => 'Murray']);
    $this->acme = Organization::create(['external_id' => (string) Str::uuid(), 'name' => 'Acme Rockets']);
    $this->zeta = Organization::create(['external_id' => (string) Str::uuid(), 'name' => 'Zeta Labs']);
});

it('stores the names as ciphertext, so SQL cannot match them', function () {
    expect(DB::table('crm_people')->where('first_name', 'like', '%Ada%')->exists())->toBeFalse()
        ->and(DB::table('crm_organizations')->where('name', 'like', '%Acme%')->exists())->toBeFalse();
});

it('finds people by first, last, full and maiden name', function (string $term, string $expected) {
    expect(EncryptedContactSearch::personOptions($term))->toBe([$this->{$expected}->id => $this->{$expected}->first_name . ' ' . $this->{$expected}->last_name]);
})->with([
    ['ada', 'ada'],
    ['HOPPER', 'grace'],
    ['ada love', 'ada'],
    ['murray', 'grace'],
]);

it('labels organizations with the decrypted name, sorted and capped', function () {
    expect(EncryptedContactSearch::organizationOptions(null))->toBe([
        $this->acme->id => 'Acme Rockets',
        $this->zeta->id => 'Zeta Labs',
    ]);

    expect(EncryptedContactSearch::organizationOptions('', 1))->toBe([$this->acme->id => 'Acme Rockets']);
    expect(EncryptedContactSearch::organizationOptions('zeta'))->toBe([$this->zeta->id => 'Zeta Labs']);
});

it('matches a lead by its contact or organization name', function () {
    $byPerson = Lead::create(['external_id' => (string) Str::uuid(), 'title' => 'Engine', 'person_id' => $this->ada->id]);
    $byOrganization = Lead::create(['external_id' => (string) Str::uuid(), 'title' => 'Launch', 'organization_id' => $this->zeta->id]);
    Lead::create(['external_id' => (string) Str::uuid(), 'title' => 'Unrelated']);

    $search = fn (string $term) => EncryptedContactSearch::titleOrContact(Lead::query(), $term)->pluck('id')->all();

    expect($search('lovelace'))->toBe([$byPerson->id])
        ->and($search('zeta'))->toBe([$byOrganization->id])
        ->and($search('engine'))->toBe([$byPerson->id]);
});

it('swaps a relationship Select onto decrypted search only when encryption is on', function () {
    $select = EncryptedContactSearch::personSelect(Select::make('person_id'));

    $results = (new ReflectionProperty(Select::class, 'getSearchResultsUsing'))->getValue($select);
    expect($results)->toBeInstanceOf(Closure::class);

    config(['laravel-crm.encrypt_db_fields' => false]);
    $plain = EncryptedContactSearch::personSelect(Select::make('person_id'));
    expect((new ReflectionProperty(Select::class, 'getSearchResultsUsing'))->getValue($plain))->toBeNull();
});

it('leaves the SQL path alone with encryption off', function () {
    config(['laravel-crm.encrypt_db_fields' => false]);

    $person = Person::create(['external_id' => (string) Str::uuid(), 'first_name' => 'Plain', 'last_name' => 'Text']);
    $lead = Lead::create(['external_id' => (string) Str::uuid(), 'title' => 'Plain lead', 'person_id' => $person->id]);

    expect(EncryptedContactSearch::titleOrContact(Lead::query(), 'Text')->pluck('id')->all())->toBe([$lead->id]);
});
