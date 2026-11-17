<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Deal;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\Pipeline;
use VentureDrake\LaravelCrm\Models\PipelineStage;
use VentureDrake\LaravelCrm\Models\PipelineStageProbability;
use VentureDrake\LaravelCrm\Services\PipelineStageService;
use VentureDrake\LaravelCrmFilament\RelationManagers\PipelineStagesRelationManager;
use VentureDrake\LaravelCrmFilament\Resources\Pipelines\Pages\EditPipeline;
use VentureDrake\LaravelCrmFilament\Resources\PipelineStages\Pages\CreatePipelineStage;

use function Pest\Livewire\livewire;

/**
 * Core 2.7.0 creates and reorders pipeline stages through
 * PipelineStageService: a new stage goes after the pipeline's last, positions
 * stop at 127, and a reorder made against a stale order is refused.
 */
beforeEach(function () {
    actingAsCrmAdmin();
});

function stagePipeline(string $name, string $model = Lead::class, array $orders = []): Pipeline
{
    $pipeline = Pipeline::create(['external_id' => (string) Str::uuid(), 'name' => $name, 'model' => $model]);

    foreach ($orders as $i => $order) {
        PipelineStage::create([
            'external_id' => (string) Str::uuid(),
            'pipeline_id' => $pipeline->id,
            'name' => $name . ' stage ' . ($i + 1),
            'order' => $order,
        ]);
    }

    return $pipeline;
}

it('preselects the first pipeline the select lists and hides order on create', function () {
    stagePipeline('Sales', Deal::class);
    $first = stagePipeline('Inbound');

    livewire(CreatePipelineStage::class)
        ->assertFormSet(['pipeline_id' => $first->id])
        ->assertFormFieldIsHidden('order');
});

it('appends the new stage after the last and saves color and probability', function () {
    $pipeline = stagePipeline('Inbound', Lead::class, [1, 4]);
    $probability = PipelineStageProbability::create(['name' => 'High', 'percent' => 75]);

    livewire(CreatePipelineStage::class)
        ->fillForm([
            'name' => 'Qualified',
            'color' => '#ff0000',
            'pipeline_stage_probability_id' => $probability->id,
            'description' => 'Ready to talk',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $stage = PipelineStage::query()->where('name', 'Qualified')->firstOrFail();

    expect($stage->pipeline_id)->toBe($pipeline->id)
        ->and((int) $stage->order)->toBe(5)
        ->and($stage->color)->toBe('#ff0000')
        ->and((int) $stage->pipeline_stage_probability_id)->toBe($probability->id)
        ->and($stage->description)->toBe('Ready to talk')
        ->and($stage->external_id)->not->toBeEmpty();
});

it('shows the service refusal when the pipeline has no positions left', function () {
    stagePipeline('Full', Lead::class, [PipelineStageService::MAX_ORDER]);

    livewire(CreatePipelineStage::class)
        ->fillForm(['name' => 'One too many'])
        ->call('create')
        ->assertNotified(__('laravel-crm::lang.pipeline_stage_order_capacity'));

    expect(PipelineStage::query()->where('name', 'One too many')->exists())->toBeFalse();
});

it('creates a stage from the pipeline page through the service, appended to that pipeline', function () {
    $pipeline = stagePipeline('Inbound', Lead::class, [1, 2]);
    stagePipeline('Other', Lead::class, [9]);

    livewire(PipelineStagesRelationManager::class, ['ownerRecord' => $pipeline, 'pageClass' => EditPipeline::class])
        ->callAction(TestAction::make('create')->table(), data: ['name' => 'Won', 'description' => 'Closed'])
        ->assertHasNoFormErrors();

    $stage = PipelineStage::query()->where('name', 'Won')->firstOrFail();

    expect($stage->pipeline_id)->toBe($pipeline->id)
        ->and((int) $stage->order)->toBe(3)
        ->and($stage->description)->toBe('Closed');
});

it('refuses a relation manager create past the last position', function () {
    $pipeline = stagePipeline('Full', Lead::class, [PipelineStageService::MAX_ORDER]);

    livewire(PipelineStagesRelationManager::class, ['ownerRecord' => $pipeline, 'pageClass' => EditPipeline::class])
        ->callAction(TestAction::make('create')->table(), data: ['name' => 'One too many'])
        ->assertNotified(__('laravel-crm::lang.pipeline_stage_order_capacity'));

    expect(PipelineStage::query()->where('name', 'One too many')->exists())->toBeFalse();
});

it('reorders the stages through the service, renumbering from 1', function () {
    $pipeline = stagePipeline('Inbound', Lead::class, [3, 7, 9]);
    [$one, $two, $three] = $pipeline->pipelineStages()->orderBy('order')->pluck('id')->all();

    livewire(PipelineStagesRelationManager::class, ['ownerRecord' => $pipeline, 'pageClass' => EditPipeline::class])
        ->call('reorderTable', [(string) $three, (string) $one, (string) $two]);

    expect(PipelineStage::query()->whereKey([$one, $two, $three])->orderBy('order')->pluck('order', 'id')->all())
        ->toBe([$three => 1, $one => 2, $two => 3]);
});

it('refuses a reorder made against an order that changed since the table rendered', function () {
    $pipeline = stagePipeline('Inbound', Lead::class, [1, 2]);
    [$one, $two] = $pipeline->pipelineStages()->orderBy('order')->pluck('id')->all();

    $component = livewire(PipelineStagesRelationManager::class, ['ownerRecord' => $pipeline, 'pageClass' => EditPipeline::class]);

    // Someone else moves a stage in the meantime.
    PipelineStage::query()->whereKey($one)->update(['order' => 5]);

    $component
        ->call('reorderTable', [(string) $two, (string) $one])
        ->assertNotified(__('laravel-crm::lang.pipeline_stage_order_changed'));

    expect(PipelineStage::query()->whereKey([$one, $two])->pluck('order', 'id')->map(fn ($order) => (int) $order)->all())
        ->toBe([$one => 5, $two => 2]);
});

it('refuses a reorder that leaves a stage out', function () {
    $pipeline = stagePipeline('Inbound', Lead::class, [1, 2, 3]);
    [$one, $two] = $pipeline->pipelineStages()->orderBy('order')->pluck('id')->all();

    livewire(PipelineStagesRelationManager::class, ['ownerRecord' => $pipeline, 'pageClass' => EditPipeline::class])
        ->call('reorderTable', [(string) $two, (string) $one])
        ->assertNotified(__('laravel-crm::lang.pipeline_stage_order_invalid'));

    expect((int) PipelineStage::query()->find($one)->order)->toBe(1);
});
