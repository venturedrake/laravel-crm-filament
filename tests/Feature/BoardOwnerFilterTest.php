<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrmFilament\Concerns\FiltersBoardByOwner;
use VentureDrake\LaravelCrmFilament\Pages\CalendarPage;
use VentureDrake\LaravelCrmFilament\Resources\Deals\Pages\DealKanban;
use VentureDrake\LaravelCrmFilament\Resources\Features\Pages\FeatureKanban;
use VentureDrake\LaravelCrmFilament\Resources\Leads\Pages\LeadKanban;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\QuoteKanban;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

/**
 * The Owner dropdown on the boards and the calendar rendered one <option> per
 * user on every round trip — the pattern core 2.4.4 measured at 31.7 MB a page
 * on a large install. It now searches on the server, caps the list, and keeps
 * the selected owner in it.
 */
afterEach(function () {
    DealKanban::$ownerOptionsLimit = 20;
});

function seedUsers(int $n, string $prefix = 'User'): array
{
    return collect(range(1, $n))
        ->map(fn (int $i) => User::create([
            'name' => sprintf('%s %02d', $prefix, $i),
            'email' => Str::random(8) . "-{$i}@example.com",
            'password' => 'secret',
        ]))
        ->all();
}

it('is shared by every board and the calendar', function (string $page) {
    expect(class_uses_recursive($page))->toContain(FiltersBoardByOwner::class);
})->with([DealKanban::class, LeadKanban::class, QuoteKanban::class, FeatureKanban::class, CalendarPage::class]);

it('caps the options and searches the rest on the server', function () {
    DealKanban::$ownerOptionsLimit = 3;
    seedUsers(5, 'Alpha');
    $beta = seedUsers(1, 'Beta')[0];

    $board = new DealKanban;

    expect($board->getOwners())->toHaveCount(3)
        ->and($board->getOwners()->has($beta->id))->toBeFalse()
        ->and($board->ownerSearchEnabled())->toBeTrue();

    $board->ownerSearch = 'beta';

    expect($board->getOwners()->all())->toBe([$beta->id => 'Beta 01']);
});

it('keeps the selected owner in the list when the search no longer matches it', function () {
    DealKanban::$ownerOptionsLimit = 3;
    $alphas = seedUsers(5, 'Alpha');

    $board = new DealKanban;
    $board->ownerFilter = $alphas[4]->id;
    $board->ownerSearch = 'Alpha 01';

    expect($board->getOwners()->keys()->all())->toBe([$alphas[4]->id, $alphas[0]->id]);
});

it('keeps the plain dropdown for a team that fits under the cap', function () {
    seedUsers(2);

    expect((new DealKanban)->ownerSearchEnabled())->toBeFalse();
});
