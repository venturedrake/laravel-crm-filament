<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Fluent;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use VentureDrake\LaravelCrm\Models\Deal;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\Pipeline;
use VentureDrake\LaravelCrm\Models\PipelineStage;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrm\Services\DealService;
use VentureDrake\LaravelCrmFilament\Resources\Deals\DealResource;
use VentureDrake\LaravelCrmFilament\Resources\Leads\LeadResource;
use VentureDrake\LaravelCrmFilament\Resources\Leads\Pages\ListLeads;
use VentureDrake\LaravelCrmFilament\Resources\Leads\Pages\ViewLead;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * Core 2.7.0: a lead converts to a deal once. The deal keeps the lead in
 * `lead_id` and starts on the deal pipeline's first stage, the lead is
 * marked converted through LeadService (which also moves it to a won stage),
 * a converted lead can't be converted again, and deleting its only deal
 * lets it convert again.
 */
beforeEach(function () {
    actingAsCrmAdmin();

    Setting::updateOrCreate(['name' => 'currency'], ['external_id' => (string) Str::uuid(), 'value' => 'USD']);
    app('laravel-crm.settings')->forgetCache();

    $this->dealPipeline = conversionPipeline(Deal::class, [1 => 'Second', 0 => 'First']);
});

/**
 * @param  array<int, string>  $stages  order => name
 */
function conversionPipeline(string $model, array $stages): Pipeline
{
    $pipeline = Pipeline::create([
        'external_id' => (string) Str::uuid(),
        'name' => class_basename($model) . ' Pipeline',
        'model' => $model,
    ]);

    foreach ($stages as $order => $name) {
        $pipeline->pipelineStages()->create([
            'external_id' => (string) Str::uuid(),
            'name' => $name,
            'order' => $order,
        ]);
    }

    return $pipeline;
}

function conversionLead(array $attributes = []): Lead
{
    return Lead::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Quinn Tester Lead',
        'amount' => 100,
        'currency' => 'USD',
        'person_id' => Person::create(['external_id' => (string) Str::uuid(), 'first_name' => 'Quinn', 'last_name' => 'Tester'])->id,
        ...$attributes,
    ])->fresh();
}

/**
 * The same convertAction() the view page's header uses, called from the
 * list's row (the view page's tabbed relation managers don't re-render
 * under the Livewire test harness).
 */
function convertFromLeadList(Lead $lead): mixed
{
    return livewire(ListLeads::class)
        ->callAction(TestAction::make('convertToDeal')->table($lead));
}

it('links the deal to the lead, starts it on the first deal stage and marks the lead converted', function (string $wonStage) {
    $leadPipeline = conversionPipeline(Lead::class, ['New', $wonStage, 'Closed Lost']);
    $new = $leadPipeline->pipelineStages()->where('name', 'New')->first();
    $lead = conversionLead(['pipeline_id' => $leadPipeline->id, 'pipeline_stage_id' => $new->id]);

    convertFromLeadList($lead)
        ->assertNotified(__('laravel-crm-filament::labels.actions.convert_to_deal'));

    $deal = Deal::query()->sole();
    $lead->refresh();

    expect($deal->lead_id)->toBe($lead->id)
        ->and($deal->pipeline_id)->toBe($this->dealPipeline->id)
        ->and($deal->pipelineStage->name)->toBe('First')
        ->and($deal->person_id)->toBe($lead->person_id)
        ->and((int) $deal->getRawOriginal('amount'))->toBe((int) $lead->getRawOriginal('amount'))
        ->and($lead->isConverted())->toBeTrue()
        ->and($lead->pipelineStage->name)->toBe($wonStage)
        ->and($lead->deal->is($deal))->toBeTrue();
})->with(['Closed Won', 'Won', 'Converted']);

it('leaves the lead on its stage when its pipeline has no won stage', function () {
    $leadPipeline = conversionPipeline(Lead::class, ['New', 'Qualified']);
    $new = $leadPipeline->pipelineStages()->where('name', 'New')->first();
    $lead = conversionLead(['pipeline_id' => $leadPipeline->id, 'pipeline_stage_id' => $new->id]);

    LeadResource::doConvertToDeal($lead);

    expect($lead->isConverted())->toBeTrue()
        ->and($lead->pipeline_stage_id)->toBe($new->id);
});

it('makes no second deal from a lead converted after the page loaded it', function () {
    $lead = conversionLead();
    $stale = Lead::query()->findOrFail($lead->id);

    LeadResource::doConvertToDeal($lead);

    // $stale still has converted_at null in memory.
    expect($stale->converted_at)->toBeNull();

    LeadResource::doConvertToDeal($stale);

    expect(Deal::query()->count())->toBe(1);
});

it('hides Convert on a converted lead', function () {
    $lead = conversionLead(['converted_at' => now()]);

    livewire(ViewLead::class, ['record' => $lead->external_id])
        ->assertActionHidden('convertToDeal');
});

it('refuses with a notification when the deal service turns the lead away', function () {
    $lead = conversionLead();
    $message = ucfirst(__('laravel-crm::lang.lead_already_converted'));

    // The service's own check, for a lead converted between the action's
    // re-read and the deal being made.
    $service = Mockery::mock(DealService::class);
    $service->shouldReceive('create')->andThrow(ValidationException::withMessages(['lead_id' => $message]));
    app()->instance(DealService::class, $service);

    convertFromLeadList($lead)
        ->assertNotified($message);

    expect(Deal::query()->count())->toBe(0)
        ->and($lead->fresh()->isConverted())->toBeFalse();
});

it('refuses a converted lead in DealService, so a second deal is never made', function () {
    $lead = conversionLead(['converted_at' => now()]);

    expect(fn () => app(DealService::class)->create(new Fluent([
        'lead_id' => $lead->id,
        'title' => 'Second deal',
        'currency' => 'USD',
    ])))->toThrow(ValidationException::class);

    expect(Deal::query()->count())->toBe(0);
});

it('shows a dated Converted badge and links the deal', function () {
    app('laravel-crm.settings')->set('date_format', 'd/m/Y');
    app('laravel-crm.settings')->forgetCache();

    $lead = conversionLead(['converted_at' => '2026-11-01 11:49:11']);
    $deal = Deal::create(['external_id' => (string) Str::uuid(), 'title' => 'From the lead', 'lead_id' => $lead->id, 'currency' => 'USD']);

    livewire(ViewLead::class, ['record' => $lead->external_id])
        ->assertSee(ucfirst(__('laravel-crm::lang.converted')) . ' 01/11/2026')
        ->assertSee(DealResource::getUrl('view', ['record' => $deal]), false)
        ->assertSee($deal->fresh()->deal_id);
});

it('shows the badge alone for a lead converted before deals kept their lead', function () {
    $lead = conversionLead(['converted_at' => now()]);

    livewire(ViewLead::class, ['record' => $lead->external_id])
        ->assertSee(ucfirst(__('laravel-crm::lang.converted')))
        ->assertDontSee('/deals/', false);
});

it('does not link a deal the user cannot view', function () {
    $lead = conversionLead(['converted_at' => now()]);
    $deal = Deal::create(['external_id' => (string) Str::uuid(), 'title' => 'From the lead', 'lead_id' => $lead->id, 'currency' => 'USD']);

    RoleSeeder::seed();
    $user = User::create([
        'name' => 'Lead Viewer',
        'email' => 'lead-viewer-' . Str::random(8) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $user->givePermissionTo('view crm leads');
    $this->actingAs($user->fresh());

    livewire(ViewLead::class, ['record' => $lead->external_id])
        ->assertSee(ucfirst(__('laravel-crm::lang.converted')))
        ->assertDontSee(DealResource::getUrl('view', ['record' => $deal]), false);
});

it('lets the lead convert again once its only deal is deleted', function () {
    $lead = conversionLead();

    LeadResource::doConvertToDeal($lead);
    Deal::query()->sole()->delete();

    expect($lead->fresh()->isConverted())->toBeFalse();

    livewire(ViewLead::class, ['record' => $lead->external_id])
        ->assertActionVisible('convertToDeal');

    convertFromLeadList($lead->fresh());

    expect($lead->fresh()->isConverted())->toBeTrue()
        ->and(Deal::query()->count())->toBe(1)
        ->and($lead->fresh()->deal->id)->toBe(Deal::query()->sole()->id);
});

it('starts the deal with no stage when there is no deal pipeline', function () {
    PipelineStage::query()->forceDelete();
    Pipeline::query()->forceDelete();

    $lead = conversionLead();

    LeadResource::doConvertToDeal($lead);

    expect(Deal::query()->sole()->pipeline_stage_id)->toBeNull()
        ->and($lead->isConverted())->toBeTrue();
});
