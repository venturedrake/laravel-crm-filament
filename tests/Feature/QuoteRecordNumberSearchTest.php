<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\ListQuotes;

use function Pest\Livewire\livewire;

/**
 * The quote number and reference columns search with core 2.7.0's
 * RecordSearch: the number answers to its stored form, its digits and any
 * case of its prefix, and `%` / `_` in a term are literal.
 */
beforeEach(function () {
    actingAsCrmAdmin();

    Setting::updateOrCreate(['name' => 'currency'], ['external_id' => (string) Str::uuid(), 'value' => 'USD']);
    app('laravel-crm.settings')->set('quote_prefix', 'QU-');
    app('laravel-crm.settings')->forgetCache();
});

function quoteSearchQuote(int $number, array $attributes = []): Quote
{
    $quote = Quote::create(['title' => 'Fit-out', 'currency' => 'USD']);

    // The observer numbers it; pin the number the test searches for.
    $quote->forceFill([
        'prefix' => 'QU-',
        'number' => $number,
        'quote_id' => 'QU-' . $number,
        ...$attributes,
    ])->saveQuietly();

    return $quote->fresh();
}

it('finds a quote by its number however it is typed', function (string $term) {
    $match = quoteSearchQuote(3002);
    $other = quoteSearchQuote(4100);

    livewire(ListQuotes::class)
        ->searchTable($term)
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$other]);
})->with(['QU-3002', '3002', 'qu-3002']);

it('finds a quote by its reference', function () {
    $match = quoteSearchQuote(3002, ['reference' => 'PO-Northwind-77']);
    $other = quoteSearchQuote(4100, ['reference' => 'Something else']);

    livewire(ListQuotes::class)
        ->searchTable('northwind')
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$other]);
});

it('treats % in a search term as a literal character', function () {
    $match = quoteSearchQuote(3002, ['reference' => '10% off']);
    $other = quoteSearchQuote(4100, ['reference' => '10 and more off']);

    livewire(ListQuotes::class)
        ->searchTable('10%')
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$other]);
});
