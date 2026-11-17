<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Deal;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Resources\Deals\Pages\ListDeals;
use VentureDrake\LaravelCrmFilament\Resources\Leads\Pages\ListLeads;

use function Pest\Livewire\livewire;

/**
 * Core 2.7.0's lead and deal lists search the record number with
 * RecordSearch::orNumber (SearchesRecords): the stored number, its digits and
 * any case of its prefix. The title and contact search is unchanged, and
 * still matches decrypted names with encryption on.
 */
beforeEach(function () {
    actingAsCrmAdmin();

    Setting::updateOrCreate(['name' => 'currency'], ['external_id' => (string) Str::uuid(), 'value' => 'USD']);
    app('laravel-crm.settings')->set('lead_prefix', 'LD-');
    app('laravel-crm.settings')->set('deal_prefix', 'DL-');
    app('laravel-crm.settings')->forgetCache();
});

/**
 * @param  class-string<Lead|Deal>  $model
 */
function numberSearchRecord(string $model, string $prefix, int $number, array $attributes = []): Lead | Deal
{
    $record = $model::create(['external_id' => (string) Str::uuid(), 'title' => 'Fit-out', 'currency' => 'USD', ...$attributes]);

    // The observer numbers it; pin the number the test searches for.
    $key = $model === Lead::class ? 'lead_id' : 'deal_id';
    $record->forceFill(['prefix' => $prefix, 'number' => $number, $key => $prefix . $number])->saveQuietly();

    return $record->fresh();
}

dataset('numberSearchLists', [
    'leads' => [ListLeads::class, Lead::class, 'LD-'],
    'deals' => [ListDeals::class, Deal::class, 'DL-'],
]);

it('finds a lead or deal by its number however it is typed', function (string $list, string $model, string $prefix) {
    $match = numberSearchRecord($model, $prefix, 3002);
    $other = numberSearchRecord($model, $prefix, 4100);

    foreach ([$prefix . '3002', '3002', strtolower($prefix) . '3002'] as $term) {
        livewire($list)
            ->searchTable($term)
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    }
})->with('numberSearchLists');

it('still finds a lead or deal by its title or contact', function (string $list, string $model, string $prefix) {
    $person = Person::create(['external_id' => (string) Str::uuid(), 'first_name' => 'Ada', 'last_name' => 'Lovelace']);
    $byTitle = numberSearchRecord($model, $prefix, 3002, ['title' => 'Northwind fit-out']);
    $byContact = numberSearchRecord($model, $prefix, 3003, ['person_id' => $person->id]);
    $other = numberSearchRecord($model, $prefix, 4100);

    livewire($list)
        ->searchTable('northwind')
        ->assertCanSeeTableRecords([$byTitle])
        ->assertCanNotSeeTableRecords([$byContact, $other]);

    livewire($list)
        ->searchTable('lovelace')
        ->assertCanSeeTableRecords([$byContact])
        ->assertCanNotSeeTableRecords([$byTitle, $other]);
})->with('numberSearchLists');

it('keeps the contact search on decrypted names with encryption on', function (string $list, string $model, string $prefix) {
    config(['laravel-crm.encrypt_db_fields' => true]);

    $person = Person::create(['external_id' => (string) Str::uuid(), 'first_name' => 'Ada', 'last_name' => 'Lovelace']);
    $byContact = numberSearchRecord($model, $prefix, 3003, ['person_id' => $person->id]);
    $byNumber = numberSearchRecord($model, $prefix, 4100);

    livewire($list)
        ->searchTable('lovelace')
        ->assertCanSeeTableRecords([$byContact])
        ->assertCanNotSeeTableRecords([$byNumber]);

    livewire($list)
        ->searchTable($prefix . '4100')
        ->assertCanSeeTableRecords([$byNumber])
        ->assertCanNotSeeTableRecords([$byContact]);
})->with('numberSearchLists');

it('finds a record by its number and the current prefix when it stored neither', function (string $list, string $model, string $prefix) {
    $match = numberSearchRecord($model, $prefix, 3002);
    $key = $model === Lead::class ? 'lead_id' : 'deal_id';
    // Saved before numbering: no stored number string or prefix, only the
    // digits, which the list shows with the current prefix.
    $match->forceFill(['prefix' => null, $key => null])->saveQuietly();
    $other = numberSearchRecord($model, $prefix, 4100);

    livewire($list)
        ->searchTable($prefix . '3002')
        ->assertCanSeeTableRecords([$match->fresh()])
        ->assertCanNotSeeTableRecords([$other]);
})->with('numberSearchLists');
