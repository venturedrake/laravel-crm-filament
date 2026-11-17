<?php

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\EditQuote;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\ListQuotes;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\ViewQuote;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\QuoteResource;

use function Pest\Livewire\livewire;

/**
 * Core 2.7.0 locks a quote once it is accepted or has an order: its lines and
 * totals are what was agreed. The edit page turns it away with the reason,
 * QuoteService::update() refuses it, Edit is disabled with the reason, and a
 * quote with an order cannot be deleted (QuotePolicy::delete denies with
 * deleteBlockedReason()). See core's tests/Feature/Livewire/QuoteLockTest.php
 * and QuoteDeleteTest.php.
 */
beforeEach(function () {
    actingAsCrmAdmin();

    Setting::updateOrCreate(['name' => 'currency'], ['external_id' => (string) Str::uuid(), 'value' => 'USD']);
    app('laravel-crm.settings')->forgetCache();
});

function quoteLockQuote(array $attributes = []): Quote
{
    return Quote::create(['title' => 'Fit-out', 'currency' => 'USD', ...$attributes])->fresh();
}

function quoteLockOrdered(): Quote
{
    $quote = quoteLockQuote(['accepted_at' => now()]);
    Order::create(['quote_id' => $quote->id, 'currency' => 'USD']);

    return $quote->fresh();
}

/**
 * The table's row action of $name for $quote, as rendered.
 */
function quoteLockRowAction(string $name, Quote $quote)
{
    $action = livewire(ListQuotes::class)->instance()->getTable()->getAction($name);

    return $action->record($quote);
}

it('can edit an open quote but not an accepted or ordered one', function () {
    expect(QuoteResource::canEdit(quoteLockQuote()))->toBeTrue()
        ->and(QuoteResource::canEdit(quoteLockQuote(['accepted_at' => now()])))->toBeFalse()
        ->and(QuoteResource::canEdit(quoteLockOrdered()))->toBeFalse();
});

it('sends a locked quote\'s edit page to the View page with the reason', function (string $state, string $reason) {
    $quote = $state === 'ordered' ? quoteLockOrdered() : quoteLockQuote(['accepted_at' => now()]);

    livewire(EditQuote::class, ['record' => $quote->external_id])
        ->assertRedirect(QuoteResource::getUrl('view', ['record' => $quote]))
        ->assertNotified(ucfirst(__("laravel-crm::lang.{$reason}")));
})->with([
    'accepted' => ['accepted', 'quote_locked_accepted'],
    'ordered' => ['ordered', 'quote_locked_ordered'],
]);

it('opens an open quote\'s edit page', function () {
    $quote = quoteLockQuote();

    livewire(EditQuote::class, ['record' => $quote->external_id])
        ->assertNoRedirect()
        ->assertSchemaStateSet(['title' => 'Fit-out']);
});

it('refuses a save once the quote was accepted after the form opened, with a notification', function () {
    $quote = quoteLockQuote();

    $page = livewire(EditQuote::class, ['record' => $quote->external_id]);

    // Accepted in another tab, or in the portal, while this form was open.
    Quote::whereKey($quote->id)->update(['accepted_at' => now()]);

    $page->fillForm(['title' => 'Changed after accepting'])
        ->call('save')
        ->assertNotified(ucfirst(__('laravel-crm::lang.quote_locked_accepted')));

    expect($quote->fresh()->title)->toBe('Fit-out');
});

it('disables Edit on a locked quote with the reason as the tooltip', function () {
    $open = quoteLockQuote();
    $accepted = quoteLockQuote(['accepted_at' => now()]);

    livewire(ListQuotes::class)
        ->assertActionEnabled(TestAction::make('edit')->table($open))
        ->assertActionDisabled(TestAction::make('edit')->table($accepted));

    expect(quoteLockRowAction('edit', $accepted)->getTooltip())
        ->toBe(ucfirst(__('laravel-crm::lang.quote_locked_accepted')));

    $viewEdit = QuoteResource::lockAwareEditAction(EditAction::make())->record($accepted);
    expect($viewEdit->isDisabled())->toBeTrue()
        ->and($viewEdit->getTooltip())->toBe($accepted->lockReason());
});

it('cannot delete a quote with an order, and says why', function () {
    $ordered = quoteLockOrdered();
    $reason = ucfirst(__('laravel-crm::lang.quote_delete_ordered'));

    expect(QuoteResource::canDelete($ordered))->toBeFalse()
        ->and(Gate::inspect('delete', $ordered)->message())->toBe($reason);

    livewire(ListQuotes::class)
        ->assertActionVisible(TestAction::make('delete')->table($ordered))
        ->assertActionDisabled(TestAction::make('delete')->table($ordered));

    expect(quoteLockRowAction('delete', $ordered)->getTooltip())->toBe($reason);

    expect($ordered->fresh())->not->toBeNull();
});

it('deletes an open quote', function () {
    $quote = quoteLockQuote();

    livewire(ListQuotes::class)
        ->assertActionEnabled(TestAction::make('delete')->table($quote))
        ->callAction(TestAction::make('delete')->table($quote));

    expect(Quote::find($quote->id))->toBeNull();
});

it('puts the delete reason on the View and Edit page Delete actions too', function () {
    foreach ([ViewQuote::class, EditQuote::class] as $page) {
        $method = new ReflectionMethod($page, 'getHeaderActions');
        $actions = $method->invoke((new ReflectionClass($page))->newInstanceWithoutConstructor());

        $delete = collect($actions)->first(fn ($action) => $action instanceof DeleteAction);

        expect($delete->hasAuthorizationTooltip())->toBeTrue();
    }
});

it('shows an Ordered badge on the View page of a quote with an order', function () {
    $page = new ViewQuote;

    $page->record = quoteLockOrdered();
    expect((string) $page->getSubheading())
        ->toContain(e(ucfirst(__('laravel-crm::lang.ordered'))))
        ->toContain(e(ucfirst(__('laravel-crm::lang.quote_locked_ordered'))));

    $page->record = quoteLockQuote(['accepted_at' => now()]);
    expect((string) $page->getSubheading())->not->toContain(e(ucfirst(__('laravel-crm::lang.ordered'))));
});

it('shows a deleted contact as "Name (deleted)" without a link', function () {
    $person = Person::create(['external_id' => (string) Str::uuid(), 'first_name' => 'Pat', 'last_name' => 'Gone']);
    $organization = Organization::create(['external_id' => (string) Str::uuid(), 'name' => 'Closed Ltd']);
    $quote = quoteLockQuote(['person_id' => $person->id, 'organization_id' => $organization->id]);

    $host = livewire(ListQuotes::class)->instance();

    $entries = function (Quote $quote) use ($host): array {
        $schema = Schema::make($host)->record($quote);
        QuoteResource::infolist($schema);

        return collect($schema->getFlatComponents(withHidden: true))
            ->filter(fn ($component) => $component instanceof TextEntry)
            ->keyBy(fn (TextEntry $entry) => $entry->getName())
            ->all();
    };

    $live = $entries($quote);
    expect($live['person.name']->getState())->toBe('Pat Gone')
        ->and($live['person.name']->getUrl())->not->toBeNull()
        ->and($live['organization.name']->getState())->toBe('Closed Ltd');

    $person->delete();
    $organization->delete();

    $deleted = $entries($quote->fresh());
    $suffix = ' (' . __('laravel-crm::lang.deleted') . ')';
    expect($deleted['person.name']->getState())->toBe('Pat Gone' . $suffix)
        ->and($deleted['person.name']->getUrl())->toBeNull()
        ->and($deleted['organization.name']->getState())->toBe('Closed Ltd' . $suffix)
        ->and($deleted['organization.name']->getUrl())->toBeNull();
});
