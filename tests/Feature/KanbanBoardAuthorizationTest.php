<?php

use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use VentureDrake\LaravelCrm\Models\Call;
use VentureDrake\LaravelCrm\Models\Deal;
use VentureDrake\LaravelCrm\Models\Feature;
use VentureDrake\LaravelCrm\Models\FeatureStatus;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\Pipeline;
use VentureDrake\LaravelCrm\Models\PipelineStage;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrm\Models\Task;
use VentureDrake\LaravelCrmFilament\Resources\Calls\Pages\CallKanban;
use VentureDrake\LaravelCrmFilament\Resources\Deals\Pages\DealKanban;
use VentureDrake\LaravelCrmFilament\Resources\Features\Pages\FeatureKanban;
use VentureDrake\LaravelCrmFilament\Resources\Leads\Pages\LeadKanban;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\QuoteKanban;
use VentureDrake\LaravelCrmFilament\Resources\Tasks\Pages\TaskKanban;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

/**
 * The kanban boards' write endpoints.
 *
 * Every board reaches the server through a public Livewire method called
 * straight from the browser — `$wire.moveLead(id, stageId)` from the SortableJS
 * `onEnd`, `wire:click="convertToDeal('…')"` from a card button. Livewire
 * dispatches those by name off the request payload, so they are a public write
 * surface whatever the blade does with them.
 *
 * None of them authorized anything, and `Page::canAccess()` defaults to true,
 * so any authenticated panel user could restage any lead, deal or quote by
 * external_id, close a deal won, accept a quote, or convert a lead to a deal.
 * Core closed a narrower version of this at 2.4.1, where the batch reorder
 * authorized only the first record and then wrote every id it was handed.
 *
 * These call the page methods directly rather than through Livewire's test
 * helper: the helper's dispatch is a layer above the thing under test, and the
 * guard has to hold for any caller that reaches the method.
 */
function permissionlessKanbanUser(): User
{
    RoleSeeder::seed();

    $user = User::create([
        'name' => 'No Permissions',
        'email' => 'kanban-nobody-' . Str::random(8) . '@example.com',
        'password' => bcrypt('secret'),
    ]);

    test()->actingAs($user->fresh());

    return $user->fresh();
}

function adminKanbanUser(): User
{
    RoleSeeder::seed();

    $user = User::create([
        'name' => 'Kanban Admin',
        'email' => 'kanban-admin-' . Str::random(8) . '@example.com',
        'password' => bcrypt('secret'),
    ]);

    $user->assignRole('Admin');

    test()->actingAs($user->fresh());

    return $user->fresh();
}

function leadPipelineStages(): array
{
    $pipeline = Pipeline::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Leads',
        'model' => Lead::class,
    ]);

    return [
        PipelineStage::create(['external_id' => (string) Str::uuid(), 'pipeline_id' => $pipeline->id, 'name' => 'A', 'order' => 1]),
        PipelineStage::create(['external_id' => (string) Str::uuid(), 'pipeline_id' => $pipeline->id, 'name' => 'B', 'order' => 2]),
        $pipeline,
    ];
}

it('hides every kanban board from a user who may not view the records', function (string $page) {
    permissionlessKanbanUser();

    expect($page::canAccess())->toBeFalse();
})->with([
    LeadKanban::class,
    DealKanban::class,
    QuoteKanban::class,
    CallKanban::class,
    TaskKanban::class,
    FeatureKanban::class,
]);

it('shows the boards to an admin', function (string $page) {
    adminKanbanUser();

    expect($page::canAccess())->toBeTrue();
})->with([
    LeadKanban::class,
    DealKanban::class,
    QuoteKanban::class,
    CallKanban::class,
    TaskKanban::class,
    FeatureKanban::class,
]);

it('refuses to restage a lead for a user with no permissions', function () {
    permissionlessKanbanUser();

    [$a, $b] = leadPipelineStages();

    $lead = Lead::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Victim',
        'pipeline_stage_id' => $a->id,
    ]);

    expect(fn () => (new LeadKanban)->moveLead($lead->external_id, $b->id))
        ->toThrow(HttpException::class);

    expect($lead->fresh()->pipeline_stage_id)->toBe($a->id);
});

it('lets an admin restage a lead, so the guard is not simply refusing everyone', function () {
    adminKanbanUser();

    [$a, $b] = leadPipelineStages();

    $lead = Lead::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Movable',
        'pipeline_stage_id' => $a->id,
    ]);

    (new LeadKanban)->moveLead($lead->external_id, $b->id);

    expect($lead->fresh()->pipeline_stage_id)->toBe($b->id);
});

it('refuses to convert a lead into a deal for a user with no permissions', function () {
    permissionlessKanbanUser();

    $lead = Lead::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Not yours to convert',
    ]);

    $before = Deal::query()->count();

    expect(fn () => (new LeadKanban)->convertToDeal($lead->external_id))
        ->toThrow(HttpException::class);

    expect(Deal::query()->count())->toBe($before);
});

it('refuses to close a deal won or lost for a user with no permissions', function () {
    permissionlessKanbanUser();

    $deal = Deal::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Untouchable',
    ]);

    expect(fn () => (new DealKanban)->markWon($deal->external_id))->toThrow(HttpException::class);
    expect(fn () => (new DealKanban)->markLost($deal->external_id))->toThrow(HttpException::class);
    expect(fn () => (new DealKanban)->moveDeal($deal->external_id, null))->toThrow(HttpException::class);

    expect($deal->fresh()->closed_at)->toBeNull();
});

it('refuses to accept or reject a quote for a user with no permissions', function () {
    permissionlessKanbanUser();

    $quote = Quote::create([
        'external_id' => (string) Str::uuid(),
        'quote_id' => 'QUO-9',
        'title' => 'Untouchable',
        'currency' => 'USD',
    ]);

    expect(fn () => (new QuoteKanban)->markAccepted($quote->external_id))->toThrow(HttpException::class);
    expect(fn () => (new QuoteKanban)->markRejected($quote->external_id))->toThrow(HttpException::class);
    expect(fn () => (new QuoteKanban)->moveQuote($quote->external_id, null))->toThrow(HttpException::class);

    expect($quote->fresh()->accepted_at)->toBeNull()
        ->and($quote->fresh()->rejected_at)->toBeNull();
});

it('refuses to complete a task for a user with no permissions', function () {
    permissionlessKanbanUser();

    $task = Task::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Untouchable',
    ]);

    expect(fn () => (new TaskKanban)->moveTask($task->external_id, 'completed'))
        ->toThrow(HttpException::class);

    expect($task->fresh()->completed_at)->toBeNull();
});

it('refuses to finish a call for a user with no permissions', function () {
    permissionlessKanbanUser();

    $call = Call::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Untouchable',
    ]);

    expect(fn () => (new CallKanban)->moveCall($call->external_id, 'done'))
        ->toThrow(HttpException::class);

    expect($call->fresh()->finish_at)->toBeNull();
});

it('refuses to restatus a feature for a user with no permissions', function () {
    permissionlessKanbanUser();

    $status = FeatureStatus::create(['external_id' => (string) Str::uuid(), 'name' => 'Planned']);

    $feature = Feature::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Untouchable',
    ]);

    expect(fn () => (new FeatureKanban)->moveFeature($feature->external_id, $status->id))
        ->toThrow(HttpException::class);

    expect($feature->fresh()->feature_status_id)->not->toBe($status->id);
});

it('still returns quietly for an id that resolves to nothing, rather than leaking existence', function () {
    permissionlessKanbanUser();

    // The miss check runs before the policy check, so an unauthorized caller
    // cannot use the 403-vs-silence difference to probe which external_ids
    // exist… which is only true while the miss returns rather than aborting.
    expect(fn () => (new LeadKanban)->moveLead((string) Str::uuid(), null))
        ->not->toThrow(HttpException::class);
});
