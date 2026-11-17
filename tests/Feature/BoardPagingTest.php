<?php

use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use VentureDrake\LaravelCrm\Models\Deal;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\Pipeline;
use VentureDrake\LaravelCrm\Models\PipelineStage;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrmFilament\Resources\Deals\Pages\DealKanban;
use VentureDrake\LaravelCrmFilament\Resources\Leads\Pages\LeadKanban;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\QuoteKanban;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\QuoteResource;
use VentureDrake\LaravelCrmFilament\Support\MoneyForm;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * Core 2.7.0's paged boards (Livewire\PipelineBoard): one pipeline's stages,
 * per_column cards a column with Load more, headers counted and totalled per
 * currency in one grouped query, drops only onto the board's own stages, and
 * on the quote board the QuoteStateService rules for a move.
 */
beforeEach(function () {
    $this->admin = actingAsCrmAdmin();

    config(['laravel-crm.board.per_column' => 3]);
});

/**
 * @return array{0: Pipeline, 1: PipelineStage, 2: PipelineStage}
 */
function boardPipeline(string $model, array $names = ['A', 'B']): array
{
    $pipeline = Pipeline::create([
        'external_id' => (string) Str::uuid(),
        'name' => class_basename($model) . ' pipeline ' . Str::random(4),
        'model' => $model,
    ]);

    $stages = collect($names)->values()->map(fn (string $name, int $i) => PipelineStage::create([
        'external_id' => (string) Str::uuid(),
        'pipeline_id' => $pipeline->id,
        'name' => $name,
        'order' => $i + 1,
    ]));

    return [$pipeline, ...$stages->all()];
}

function boardLead(PipelineStage $stage, array $attributes = []): Lead
{
    return Lead::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Lead ' . Str::random(6),
        'amount' => 10,
        'currency' => 'USD',
        'pipeline_id' => $stage->pipeline_id,
        'pipeline_stage_id' => $stage->id,
        ...$attributes,
    ]);
}

function boardQuote(PipelineStage $stage, array $attributes = []): Quote
{
    return Quote::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Quote ' . Str::random(6),
        'currency' => 'USD',
        'pipeline_id' => $stage->pipeline_id,
        'pipeline_stage_id' => $stage->id,
        ...$attributes,
    ]);
}

it('loads per_column cards a column, in pipeline_stage_order, created_at, id order', function () {
    [, $a] = boardPipeline(Lead::class);

    // Created in one second, so leads[0] and leads[1] tie on order and created_at and id decides.
    $leads = collect([2, 2, 1, 3, 3])->map(fn (int $order) => boardLead($a, ['pipeline_stage_order' => $order]));

    $cards = (new LeadKanban)->getLeadsByStage()[$a->id];

    expect($cards->pluck('id')->all())->toBe([$leads[2]->id, $leads[0]->id, $leads[1]->id]);
});

it('counts and totals the whole column per currency, not just the cards on screen', function () {
    [, $a, $b] = boardPipeline(Lead::class);

    collect(range(1, 5))->each(fn () => boardLead($a));
    boardLead($a, ['amount' => 7, 'currency' => 'EUR']);
    boardLead($a, ['amount' => null]);

    $board = new LeadKanban;
    $summary = $board->getStageSummaries();

    expect($board->getLeadsByStage()[$a->id])->toHaveCount(3)
        ->and($summary[$a->id]['count'])->toBe(7)
        ->and($summary[$a->id]['totals'])->toBe([MoneyForm::display(700, 'EUR'), MoneyForm::display(5000, 'USD')])
        ->and($summary[$a->id]['hasMore'])->toBeTrue()
        ->and($summary[$b->id])->toBe(['count' => 0, 'totals' => [], 'hasMore' => false]);
});

it('adds the next page of a column with loadMore and shows the button only while more remain', function () {
    [, $a, $b] = boardPipeline(Lead::class);

    collect(range(1, 5))->each(fn () => boardLead($a));
    boardLead($b);

    $component = livewire(LeadKanban::class)
        ->assertSee(ucfirst(__('laravel-crm::lang.load_more')))
        ->assertSeeHtml('wire:click="loadMore(' . $a->id . ')"')
        ->assertDontSeeHtml('wire:click="loadMore(' . $b->id . ')"')
        ->call('loadMore', $a->id);

    expect($component->instance()->getLeadsByStage()[$a->id])->toHaveCount(5);

    $component->assertDontSeeHtml('wire:click="loadMore(' . $a->id . ')"');
});

it('refuses to page a stage that is not on the board', function () {
    boardPipeline(Lead::class);
    [, $other] = boardPipeline(Deal::class);

    expect(fn () => (new LeadKanban)->loadMore($other->id))
        ->toThrow(fn (HttpException $e) => expect($e->getStatusCode())->toBe(404));
});

it('filters the cards, counts and totals by owner', function () {
    [, $a] = boardPipeline(Lead::class);
    $other = User::create(['name' => 'Other', 'email' => Str::random(8) . '@example.com', 'password' => 'secret']);

    $mine = collect(range(1, 4))->map(fn () => boardLead($a, ['user_owner_id' => $this->admin->id]));
    boardLead($a, ['user_owner_id' => $other->id, 'amount' => 99]);

    $board = new LeadKanban;
    $board->ownerFilter = $this->admin->id;
    $summary = $board->getStageSummaries()[$a->id];

    expect($board->getLeadsByStage()[$a->id]->pluck('user_owner_id')->unique()->all())->toBe([$this->admin->id])
        ->and($summary['count'])->toBe(4)
        ->and($summary['totals'])->toBe([MoneyForm::display(4000, 'USD')])
        ->and($summary['hasMore'])->toBeTrue();
});

it('sends every column back to its first page when the owner filter changes', function () {
    [, $a] = boardPipeline(Lead::class);
    collect(range(1, 5))->each(fn () => boardLead($a));

    livewire(LeadKanban::class)
        ->call('loadMore', $a->id)
        ->assertSet('stageLimits', [$a->id => 6])
        ->set('ownerFilter', $this->admin->id)
        ->assertSet('stageLimits', []);
});

it('shows only the first pipeline for the model, its stages ordered by order then id', function () {
    $pipeline = Pipeline::create(['external_id' => (string) Str::uuid(), 'name' => 'First', 'model' => Lead::class]);
    $late = PipelineStage::create(['external_id' => (string) Str::uuid(), 'pipeline_id' => $pipeline->id, 'name' => 'Late', 'order' => 2]);
    $tieOne = PipelineStage::create(['external_id' => (string) Str::uuid(), 'pipeline_id' => $pipeline->id, 'name' => 'Tie one', 'order' => 1]);
    $tieTwo = PipelineStage::create(['external_id' => (string) Str::uuid(), 'pipeline_id' => $pipeline->id, 'name' => 'Tie two', 'order' => 1]);

    [, $second] = boardPipeline(Lead::class, ['Second pipeline stage']);
    boardLead($second);

    $board = new LeadKanban;

    expect($board->getStages()->pluck('id')->all())->toBe([$tieOne->id, $tieTwo->id, $late->id])
        ->and($board->getLeadsByStage())->not->toHaveKey($second->id)
        ->and($board->getStageSummaries())->not->toHaveKey($second->id);
});

it('404s a move to a stage that is not on the board', function (string $page, string $model, string $method) {
    [, $a] = boardPipeline($model);
    [, $offBoard] = boardPipeline($model, ['Second pipeline']);

    $record = $model::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Card',
        'pipeline_stage_id' => $a->id,
    ]);

    foreach ([$offBoard->id, 999999, null] as $target) {
        expect(fn () => (new $page)->{$method}($record->external_id, $target))
            ->toThrow(fn (HttpException $e) => expect($e->getStatusCode())->toBe(404));
    }

    expect($record->fresh()->pipeline_stage_id)->toBe($a->id);
})->with([
    'Lead' => [LeadKanban::class, Lead::class, 'moveLead'],
    'Deal' => [DealKanban::class, Deal::class, 'moveDeal'],
    'Quote' => [QuoteKanban::class, Quote::class, 'moveQuote'],
]);

it('moves a card to another stage on the board', function () {
    [, $a, $b] = boardPipeline(Deal::class);
    $deal = Deal::create(['external_id' => (string) Str::uuid(), 'title' => 'Card', 'pipeline_stage_id' => $a->id]);

    (new DealKanban)->moveDeal($deal->external_id, $b->id);

    expect($deal->fresh()->pipeline_stage_id)->toBe($b->id)
        ->and($deal->fresh()->pipeline_id)->toBe($b->pipeline_id);
});

it('puts a moved card first in a full column and shows one card more, so nothing leaves the screen', function () {
    [, $a, $b] = boardPipeline(Lead::class);

    // Older than everything in $b, so on created_at alone the moved card would sort past the page.
    $this->travel(-1)->days();
    $moved = boardLead($a);
    $this->travelBack();

    $numbered = boardLead($b, ['pipeline_stage_order' => 1]);
    $unnumbered = collect(range(1, 4))->map(fn () => boardLead($b));
    $updatedAt = $numbered->fresh()->updated_at;

    // Later, so a peer update that touched updated_at would show.
    $this->travel(1)->hours();

    $component = livewire(LeadKanban::class)
        ->call('moveLead', $moved->external_id, $b->id)
        ->assertSet('stageLimits', [$b->id => 4, $a->id => 2]);

    $cards = $component->instance()->getLeadsByStage()[$b->id];

    // The rest keep their order: the unnumbered ones, as NULL sorted them first, then the numbered one.
    expect($cards->pluck('id')->all())->toBe([$moved->id, ...$unnumbered->take(3)->pluck('id')->all()])
        ->and($moved->fresh()->pipeline_stage_order)->toBe(1)
        ->and($numbered->fresh()->pipeline_stage_order)->toBe(3)
        ->and($numbered->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();
});

it('leaves a card where it is when it is dropped back on its own column', function () {
    [, $a] = boardPipeline(Deal::class);
    $deal = Deal::create(['external_id' => (string) Str::uuid(), 'title' => 'Card', 'pipeline_stage_id' => $a->id, 'pipeline_stage_order' => 5]);
    $peer = Deal::create(['external_id' => (string) Str::uuid(), 'title' => 'Peer', 'pipeline_stage_id' => $a->id]);

    livewire(DealKanban::class)
        ->call('moveDeal', $deal->external_id, $a->id)
        ->assertSet('stageLimits', []);

    expect($deal->fresh()->pipeline_stage_order)->toBe(5)
        ->and($peer->fresh()->pipeline_stage_order)->toBeNull();
});

it('puts a quote moved on the board first in its new column', function () {
    [, $draft, $sent] = boardPipeline(Quote::class, ['Draft', 'Sent', 'Accepted', 'Rejected']);
    $quote = boardQuote($draft);
    $peer = boardQuote($sent, ['pipeline_stage_order' => 1]);

    (new QuoteKanban)->moveQuote($quote->external_id, $sent->id);

    expect($quote->fresh()->pipeline_stage_order)->toBe(1)
        ->and($peer->fresh()->pipeline_stage_order)->toBe(3);
});

it('records no response for a quote dropped back on its own Accepted or Rejected column', function (int $column) {
    $stages = boardPipeline(Quote::class, ['Draft', 'Sent', 'Accepted', 'Rejected']);
    // Set to the column without a response, as the old panel and the quote form can; amounts that don't add up.
    $quote = boardQuote($stages[$column], ['subtotal' => 100, 'total' => 100, 'pipeline_stage_order' => 4]);

    livewire(QuoteKanban::class)
        ->call('moveQuote', $quote->external_id, $stages[$column]->id)
        ->assertNotNotified(ucfirst(__('laravel-crm::lang.quote_move_amounts')));

    $quote->refresh();
    expect($quote->accepted_at)->toBeNull()
        ->and($quote->rejected_at)->toBeNull()
        ->and($quote->pipeline_stage_order)->toBe(4);
})->with(['Accepted' => 3, 'Rejected' => 4]);

it('puts unnumbered cards before numbered ones whatever the database sorts NULL as', function () {
    [, $a] = boardPipeline(Lead::class);

    $numbered = boardLead($a, ['pipeline_stage_order' => 1]);
    $unnumbered = boardLead($a);

    expect((new LeadKanban)->getLeadsByStage()[$a->id]->pluck('id')->all())->toBe([$unnumbered->id, $numbered->id]);
});

it('totals the quote board on the quote total', function () {
    [, $draft] = boardPipeline(Quote::class, ['Draft', 'Sent', 'Accepted', 'Rejected']);

    boardQuote($draft, ['total' => 12.5]);
    boardQuote($draft, ['total' => 2.5]);

    expect((new QuoteKanban)->getStageSummaries()[$draft->id]['totals'])->toBe([MoneyForm::display(1500, 'USD')]);
});

it('stamps accepted_at when a quote is moved into Accepted', function () {
    [, $draft, , $accepted] = boardPipeline(Quote::class, ['Draft', 'Sent', 'Accepted', 'Rejected']);
    $quote = boardQuote($draft);

    livewire(QuoteKanban::class)->call('moveQuote', $quote->external_id, $accepted->id);

    $quote->refresh();
    expect($quote->pipeline_stage_id)->toBe($accepted->id)
        ->and($quote->accepted_at)->not->toBeNull()
        ->and($quote->rejected_at)->toBeNull();
});

it('refuses a quote move the state service blocks, with its reason', function () {
    [, $draft, , $accepted] = boardPipeline(Quote::class, ['Draft', 'Sent', 'Accepted', 'Rejected']);
    // A total with no lines behind it: the amounts don't add up.
    $quote = boardQuote($draft, ['subtotal' => 100, 'total' => 100]);

    $reason = ucfirst(__('laravel-crm::lang.quote_move_amounts'));

    livewire(QuoteKanban::class)
        ->call('moveQuote', $quote->external_id, $accepted->id)
        ->assertNotified($reason);

    $quote->refresh();
    expect($quote->pipeline_stage_id)->toBe($draft->id)
        ->and($quote->accepted_at)->toBeNull();

    livewire(QuoteKanban::class)
        ->call('markAccepted', $quote->external_id)
        ->assertNotified($reason);

    expect($quote->fresh()->accepted_at)->toBeNull();
});

it('accepts a quote from the card button through the state service, moving it to Accepted', function () {
    [, $draft, , $accepted] = boardPipeline(Quote::class, ['Draft', 'Sent', 'Accepted', 'Rejected']);
    $quote = boardQuote($draft);

    (new QuoteKanban)->markAccepted($quote->external_id);

    $quote->refresh();
    expect($quote->accepted_at)->not->toBeNull()
        ->and($quote->pipeline_stage_id)->toBe($accepted->id);
});

it('authorizes the quote board on update, so an ordered (locked) quote can still change column', function () {
    [, $draft, $sent] = boardPipeline(Quote::class, ['Draft', 'Sent', 'Accepted', 'Rejected']);
    $quote = boardQuote($draft);
    Order::create(['external_id' => (string) Str::uuid(), 'quote_id' => $quote->id, 'currency' => 'USD']);

    expect($quote->fresh()->isLocked())->toBeTrue()
        ->and(QuoteResource::canEdit($quote->fresh()))->toBeFalse();

    (new QuoteKanban)->moveQuote($quote->external_id, $sent->id);

    expect($quote->fresh()->pipeline_stage_id)->toBe($sent->id);
});
