<?php

use Barryvdh\DomPDF\ServiceProvider as DomPdfServiceProvider;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\Pipeline;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\ListQuotes;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\ViewQuote;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\QuoteResource;

use function Pest\Livewire\livewire;

/**
 * Core 2.7.0's QuoteStateService is the one place a quote's response changes:
 * accept / reject / unaccept / unreject move the pipeline stage with the
 * timestamp, unaccept is refused once an order exists, and a send moves a
 * Draft quote to Sent. The panel's actions go through it (see core's
 * tests/Feature/Services/QuoteStateServiceTest.php for the same setup).
 */
beforeEach(function () {
    $this->app->register(DomPdfServiceProvider::class);

    actingAsCrmAdmin();

    Setting::updateOrCreate(['name' => 'currency'], ['external_id' => (string) Str::uuid(), 'value' => 'USD']);
    app('laravel-crm.settings')->forgetCache();

    $this->pipeline = Pipeline::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Quote Pipeline',
        'model' => Quote::class,
    ]);

    $this->stages = collect(['Draft', 'Sent', 'Accepted', 'Rejected', 'Ordered'])
        ->mapWithKeys(fn (string $name, int $order) => [$name => $this->pipeline->pipelineStages()->create([
            'external_id' => (string) Str::uuid(),
            'name' => $name,
            'order' => $order,
        ])]);
});

function quoteStateInStage(string $stage, array $attributes = []): Quote
{
    return Quote::create([
        'title' => 'Sample quote',
        'currency' => 'USD',
        'pipeline_id' => test()->pipeline->id,
        'pipeline_stage_id' => test()->stages[$stage]->id,
        ...$attributes,
    ])->fresh();
}

function quoteStateOrderFrom(Quote $quote): Order
{
    return Order::create(['quote_id' => $quote->id, 'currency' => 'USD']);
}

it('accepts and rejects through the state service, moving the stage', function (string $action, string $column, string $stage, string $message) {
    $quote = quoteStateInStage('Sent');

    livewire(ListQuotes::class)
        ->callAction(TestAction::make($action)->table($quote))
        ->assertNotified(ucfirst(__("laravel-crm::lang.{$message}")));

    $fresh = $quote->fresh();
    expect($fresh->{$column})->not->toBeNull()
        ->and($fresh->pipelineStage->name)->toBe($stage);
})->with([
    'accept' => ['accept', 'accepted_at', 'Accepted', 'quote_accepted'],
    'reject' => ['reject', 'rejected_at', 'Rejected', 'quote_rejected'],
]);

it('unaccepts and unrejects back to Draft', function (string $action, string $column, string $message) {
    $quote = quoteStateInStage($column === 'accepted_at' ? 'Accepted' : 'Rejected', [$column => now()->subDay()]);

    livewire(ListQuotes::class)
        ->callAction(TestAction::make($action)->table($quote))
        ->assertNotified(ucfirst(__("laravel-crm::lang.{$message}")));

    $fresh = $quote->fresh();
    expect($fresh->{$column})->toBeNull()
        ->and($fresh->pipelineStage->name)->toBe('Draft');
})->with([
    'unaccept' => ['unaccept', 'accepted_at', 'quote_unaccepted'],
    'unreject' => ['unreject', 'rejected_at', 'quote_unrejected'],
]);

it('offers each response only in its state, as core QuoteShow does', function () {
    $open = quoteStateInStage('Draft');
    $accepted = quoteStateInStage('Accepted', ['accepted_at' => now()]);
    $rejected = quoteStateInStage('Rejected', ['rejected_at' => now()]);
    $ordered = quoteStateInStage('Ordered', ['accepted_at' => now()]);
    quoteStateOrderFrom($ordered);

    livewire(ListQuotes::class)
        ->assertActionVisible(TestAction::make('accept')->table($open))
        ->assertActionVisible(TestAction::make('reject')->table($open))
        ->assertActionHidden(TestAction::make('unaccept')->table($open))
        ->assertActionHidden(TestAction::make('unreject')->table($open))
        ->assertActionHidden(TestAction::make('accept')->table($accepted))
        ->assertActionHidden(TestAction::make('reject')->table($accepted))
        ->assertActionVisible(TestAction::make('unaccept')->table($accepted))
        ->assertActionHidden(TestAction::make('accept')->table($rejected))
        ->assertActionVisible(TestAction::make('unreject')->table($rejected))
        // An order was made from what was accepted.
        ->assertActionHidden(TestAction::make('unaccept')->table($ordered));
});

it('hides accept and reject while the amounts do not add up', function () {
    $product = Product::create(['external_id' => (string) Str::uuid(), 'name' => 'Widget']);
    $quote = quoteStateInStage('Draft', ['subtotal' => 50, 'total' => 50]);
    $quote->quoteProducts()->create([
        'product_id' => $product->id,
        'quantity' => 1,
        'price' => 1000,
        'amount' => 1000,
        'currency' => 'USD',
    ]);

    livewire(ListQuotes::class)
        ->assertActionHidden(TestAction::make('accept')->table($quote))
        ->assertActionHidden(TestAction::make('reject')->table($quote));
});

it('refuses to unaccept a quote that has an order, with core\'s reason', function () {
    $quote = quoteStateInStage('Ordered', ['accepted_at' => now()->subDay()]);
    quoteStateOrderFrom($quote);

    // The button is hidden for such a quote; this is the order landing
    // after the page was drawn.
    $action = QuoteResource::unacceptAction()->record($quote);

    expect(fn () => $action->call())->toThrow(Halt::class);

    Notification::assertNotified(ucfirst(__('laravel-crm::lang.quote_locked_ordered')));

    $fresh = $quote->fresh();
    expect($fresh->accepted_at)->not->toBeNull()
        ->and($fresh->pipelineStage->name)->toBe('Ordered');
});

it('moves a Draft quote to Sent when it is sent', function () {
    Mail::fake();

    $quote = quoteStateInStage('Draft');

    livewire(ListQuotes::class)
        ->callAction(TestAction::make('send')->table($quote), [
            'to' => 'customer@example.com',
            'subject' => 'Quote',
            'message' => 'Attached.',
        ])
        ->assertHasNoFormErrors()
        ->assertNotified(ucfirst(__('laravel-crm::lang.quote_sent_successfully')));

    expect($quote->fresh()->pipelineStage->name)->toBe('Sent');
});

it('leaves an accepted quote where it is when it is sent again', function () {
    Mail::fake();

    $quote = quoteStateInStage('Accepted', ['accepted_at' => now()]);

    livewire(ListQuotes::class)
        ->callAction(TestAction::make('send')->table($quote), [
            'to' => 'customer@example.com',
            'subject' => 'Quote',
            'message' => 'Attached.',
        ])
        ->assertHasNoFormErrors();

    expect($quote->fresh()->pipelineStage->name)->toBe('Accepted');
});

function quoteStateConvertAction(Quote $quote)
{
    $method = new ReflectionMethod(ViewQuote::class, 'quoteConvertToOrderAction');

    return $method->invoke(new ViewQuote)->record($quote);
}

it('converts an accepted quote to an order without re-stamping accepted_at', function () {
    $acceptedAt = now()->subDays(3)->startOfSecond();
    $quote = quoteStateInStage('Accepted', ['accepted_at' => $acceptedAt]);

    quoteStateConvertAction($quote)->call();

    $fresh = $quote->fresh();
    expect($fresh->orders()->count())->toBe(1)
        ->and($fresh->accepted_at->equalTo($acceptedAt))->toBeTrue()
        ->and($fresh->pipelineStage->name)->toBe('Accepted');
});

it('offers a follow-up order for what the first did not cover, and only that', function () {
    $product = Product::create(['external_id' => (string) Str::uuid(), 'name' => 'Widget']);
    // Money attributes are set in dollars; the models store cents.
    $quote = quoteStateInStage('Accepted', ['accepted_at' => now(), 'subtotal' => 30, 'total' => 30]);
    $line = $quote->quoteProducts()->create([
        'product_id' => $product->id,
        'quantity' => 3,
        'price' => 10,
        'amount' => 30,
        'currency' => 'USD',
    ]);

    $first = quoteStateOrderFrom($quote);
    $first->orderProducts()->create([
        'product_id' => $product->id,
        'quote_product_id' => $line->id,
        'quantity' => 1,
        'price' => 10,
        'amount' => 10,
        'currency' => 'USD',
    ]);

    $action = quoteStateConvertAction($quote->fresh());
    expect($action->isVisible())->toBeTrue();

    $action->call();

    $followUp = Order::where('quote_id', $quote->id)->whereKeyNot($first->id)->sole();
    $followUpLine = $followUp->orderProducts()->sole();

    expect((float) $followUpLine->quantity)->toBe(2.0)
        ->and((int) $followUpLine->amount)->toBe(2000)
        ->and($quote->fresh()->orderComplete())->toBeTrue();

    expect(quoteStateConvertAction($quote->fresh())->isVisible())->toBeFalse();
});
