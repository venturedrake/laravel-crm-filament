<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrmFilament\Resources\People\Pages\CreatePerson;
use VentureDrake\LaravelCrmFilament\Resources\People\Pages\EditPerson;

use function Pest\Livewire\livewire;

/**
 * Core requires first_name (StorePersonRequest / UpdatePersonRequest) and the
 * people table won't take a null. The Filament form has to refuse an empty
 * first name before it reaches PersonService, or the insert fails with a 500.
 */
beforeEach(function () {
    actingAsCrmAdmin();
});

it('refuses an empty person form', function () {
    livewire(CreatePerson::class)
        ->call('create')
        ->assertHasFormErrors(['first_name' => 'required']);

    expect(Person::query()->count())->toBe(0);
});

it('refuses clearing the first name on edit', function () {
    $person = Person::create([
        'external_id' => (string) Str::uuid(),
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
    ]);

    livewire(EditPerson::class, ['record' => $person->external_id])
        ->fillForm(['first_name' => ''])
        ->call('save')
        ->assertHasFormErrors(['first_name' => 'required']);

    expect($person->fresh()->first_name)->toBe('Ada');
});

it('creates a person with a first name', function () {
    livewire(CreatePerson::class)
        ->fillForm(['first_name' => 'Ada'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Person::query()->count())->toBe(1)
        ->and(Person::query()->value('first_name'))->toBe('Ada');
});
