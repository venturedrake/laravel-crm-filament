<?php

use Filament\Widgets\ChartWidget;
use VentureDrake\LaravelCrm\Models\Deal;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\Pipeline;
use VentureDrake\LaravelCrm\Models\PipelineStage;
use VentureDrake\LaravelCrmFilament\Concerns\HasChartRangeFilter;
use VentureDrake\LaravelCrmFilament\Widgets\DealsPipelineValueChart;
use VentureDrake\LaravelCrmFilament\Widgets\DealStatusDoughnutChart;
use VentureDrake\LaravelCrmFilament\Widgets\LeadsVsDealsChart;

// ----------------------------------------------------------------------------
// DealsPipelineValueChart
// ----------------------------------------------------------------------------

it('DealsPipelineValueChart extends ChartWidget', function () {
    expect(is_subclass_of(DealsPipelineValueChart::class, ChartWidget::class))->toBeTrue();
});

it('DealsPipelineValueChart declares full column span and horizontal bar shape', function () {
    $ref = new ReflectionProperty(DealsPipelineValueChart::class, 'columnSpan');
    $ref->setAccessible(true);
    expect($ref->getValue(new DealsPipelineValueChart))->toBe(1);

    $type = new ReflectionMethod(DealsPipelineValueChart::class, 'getType');
    $type->setAccessible(true);
    expect($type->invoke(new DealsPipelineValueChart))->toBe('bar');

    $opts = new ReflectionMethod(DealsPipelineValueChart::class, 'getOptions');
    $opts->setAccessible(true);
    expect($opts->invoke(new DealsPipelineValueChart))->toBe(['indexAxis' => 'y']);
});

it('DealsPipelineValueChart getFilters() returns null (timeless)', function () {
    $method = new ReflectionMethod(DealsPipelineValueChart::class, 'getFilters');
    $method->setAccessible(true);
    expect($method->invoke(new DealsPipelineValueChart))->toBeNull();
});

it('DealsPipelineValueChart getHeading() resolves the dashboard.pipeline_by_stage_deals key', function () {
    expect((new DealsPipelineValueChart)->getHeading())
        ->toBe(__('laravel-crm-filament::labels.dashboard.pipeline_by_stage_deals'));
});

it('DealsPipelineValueChart getData() sums non-closed deal amounts by pipeline stage in order', function () {
    $pipeline = Pipeline::create(['name' => 'Sales', 'model' => Deal::class, 'order' => 0]);
    $stageA = PipelineStage::create(['name' => 'Prospect', 'pipeline_id' => $pipeline->id, 'order' => 0]);
    $stageB = PipelineStage::create(['name' => 'Qualified', 'pipeline_id' => $pipeline->id, 'order' => 1]);

    // Two open deals on Prospect (stored as cents by Deal setter — 5000/2500 dollars)
    Deal::create(['title' => 'D1', 'pipeline_stage_id' => $stageA->id, 'amount' => 5000, 'closed_status' => null]);
    Deal::create(['title' => 'D2', 'pipeline_stage_id' => $stageA->id, 'amount' => 2500, 'closed_status' => null]);
    // One closed deal on Prospect — must be excluded
    Deal::create(['title' => 'D3', 'pipeline_stage_id' => $stageA->id, 'amount' => 999, 'closed_status' => 'won']);
    // One open deal on Qualified
    Deal::create(['title' => 'D4', 'pipeline_stage_id' => $stageB->id, 'amount' => 1000, 'closed_status' => null]);

    $method = new ReflectionMethod(DealsPipelineValueChart::class, 'getData');
    $method->setAccessible(true);
    $data = $method->invoke(new DealsPipelineValueChart);

    expect($data['labels'])->toContain('Prospect');
    expect($data['labels'])->toContain('Qualified');
    expect($data['datasets'])->toHaveCount(1);

    // Prospect index vs Qualified index in the labels array is same as data[] positions.
    $prospectIdx = array_search('Prospect', $data['labels'], true);
    $qualifiedIdx = array_search('Qualified', $data['labels'], true);

    // Deal setter multiplies dollars by 100 on save; chart divides sum by 100 → back to dollars.
    expect($data['datasets'][0]['data'][$prospectIdx])->toEqual(5000.0 + 2500.0);
    expect($data['datasets'][0]['data'][$qualifiedIdx])->toEqual(1000.0);
});

it('DealsPipelineValueChart only charts stages from deal pipelines', function () {
    $deals = Pipeline::create(['name' => 'Sales', 'model' => Deal::class, 'order' => 0]);
    $leads = Pipeline::create(['name' => 'Leads', 'model' => Lead::class, 'order' => 1]);
    PipelineStage::create(['name' => 'Negotiation', 'pipeline_id' => $deals->id, 'order' => 0]);
    PipelineStage::create(['name' => 'Qualified To Buy', 'pipeline_id' => $leads->id, 'order' => 1]);

    $method = new ReflectionMethod(DealsPipelineValueChart::class, 'getData');
    $method->setAccessible(true);

    expect($method->invoke(new DealsPipelineValueChart)['labels'])->toBe(['Negotiation']);
});

// ----------------------------------------------------------------------------
// LeadsVsDealsChart
// ----------------------------------------------------------------------------

it('LeadsVsDealsChart extends ChartWidget and uses HasChartRangeFilter', function () {
    expect(is_subclass_of(LeadsVsDealsChart::class, ChartWidget::class))->toBeTrue();
    expect(class_uses_recursive(LeadsVsDealsChart::class))->toContain(HasChartRangeFilter::class);
});

it('LeadsVsDealsChart defaults filter to last_30_days and returns bar type', function () {
    $widget = new LeadsVsDealsChart;
    expect($widget->filter)->toBe('last_30_days');

    $type = new ReflectionMethod(LeadsVsDealsChart::class, 'getType');
    $type->setAccessible(true);
    expect($type->invoke($widget))->toBe('bar');
});

it('LeadsVsDealsChart getHeading() resolves dashboard.leads_vs_deals key', function () {
    expect((new LeadsVsDealsChart)->getHeading())
        ->toBe(__('laravel-crm-filament::labels.dashboard.leads_vs_deals'));
});

it('LeadsVsDealsChart getData() produces two datasets labelled new_leads and deals', function () {
    $method = new ReflectionMethod(LeadsVsDealsChart::class, 'getData');
    $method->setAccessible(true);
    $data = $method->invoke(new LeadsVsDealsChart);

    expect($data)->toHaveKey('datasets');
    expect($data['datasets'])->toHaveCount(2);
    expect($data['datasets'][0]['label'])->toBe(__('laravel-crm-filament::labels.dashboard.new_leads'));
    expect($data['datasets'][1]['label'])->toBe(__('laravel-crm-filament::labels.sales.deals'));
});

it('LeadsVsDealsChart buckets leads and deals by created_at within the default window', function () {
    $today = today();

    Lead::create(['title' => 'L1', 'created_at' => $today->copy()->subDays(2)]);
    Lead::create(['title' => 'L2', 'created_at' => $today->copy()->subDays(5)]);
    Lead::create(['title' => 'L3', 'created_at' => $today->copy()->subYear()]); // out of window
    Deal::create(['title' => 'D1', 'created_at' => $today->copy()->subDays(1)]);
    Deal::create(['title' => 'D2', 'created_at' => $today->copy()->subDays(10)]);

    $method = new ReflectionMethod(LeadsVsDealsChart::class, 'getData');
    $method->setAccessible(true);
    $data = $method->invoke(new LeadsVsDealsChart);

    expect(array_sum($data['datasets'][0]['data']))->toBe(2); // 2 leads in window
    expect(array_sum($data['datasets'][1]['data']))->toBe(2); // 2 deals in window
});

// ----------------------------------------------------------------------------
// DealStatusDoughnutChart
// ----------------------------------------------------------------------------

it('DealStatusDoughnutChart extends ChartWidget and uses HasChartRangeFilter', function () {
    expect(is_subclass_of(DealStatusDoughnutChart::class, ChartWidget::class))->toBeTrue();
    expect(class_uses_recursive(DealStatusDoughnutChart::class))->toContain(HasChartRangeFilter::class);
});

it('DealStatusDoughnutChart returns doughnut chart type', function () {
    $method = new ReflectionMethod(DealStatusDoughnutChart::class, 'getType');
    $method->setAccessible(true);
    expect($method->invoke(new DealStatusDoughnutChart))->toBe('doughnut');
});

it('DealStatusDoughnutChart defaults filter to last_30_days', function () {
    expect((new DealStatusDoughnutChart)->filter)->toBe('last_30_days');
});

it('DealStatusDoughnutChart declares AC-named columnSpan array', function () {
    $ref = new ReflectionProperty(DealStatusDoughnutChart::class, 'columnSpan');
    $ref->setAccessible(true);
    expect($ref->getValue(new DealStatusDoughnutChart))
        ->toBe(['default' => 1, 'md' => 1, 'xl' => 1]);
});

it('DealStatusDoughnutChart getHeading() resolves dashboard.deal_status_distribution key', function () {
    expect((new DealStatusDoughnutChart)->getHeading())
        ->toBe(__('laravel-crm-filament::labels.dashboard.deal_status_distribution'));
});

it('DealStatusDoughnutChart getData() returns 3 slices labelled Open/Won/Lost', function () {
    $method = new ReflectionMethod(DealStatusDoughnutChart::class, 'getData');
    $method->setAccessible(true);
    $data = $method->invoke(new DealStatusDoughnutChart);

    expect($data['datasets'])->toHaveCount(1);
    expect($data['datasets'][0]['data'])->toHaveCount(3);
    expect($data['labels'])->toBe([
        __('laravel-crm-filament::labels.dashboard.status_open'),
        __('laravel-crm-filament::labels.dashboard.status_won'),
        __('laravel-crm-filament::labels.dashboard.status_lost'),
    ]);
});

it('DealStatusDoughnutChart counts open/won/lost deals via closed_status semantics', function () {
    $today = today();

    // Open deal (no closed_status, created inside window)
    Deal::create(['title' => 'Open1', 'closed_status' => null, 'created_at' => $today->copy()->subDays(2)]);
    Deal::create(['title' => 'Open2', 'closed_status' => null, 'created_at' => $today->copy()->subDays(3)]);

    // Won deal (closed within window)
    Deal::create(['title' => 'Won1', 'closed_status' => 'won', 'closed_at' => $today->copy()->subDays(4), 'created_at' => $today->copy()->subDays(4)]);

    // Lost deal (closed within window)
    Deal::create(['title' => 'Lost1', 'closed_status' => 'lost', 'closed_at' => $today->copy()->subDays(5), 'created_at' => $today->copy()->subDays(5)]);
    Deal::create(['title' => 'Lost2', 'closed_status' => 'lost', 'closed_at' => $today->copy()->subDays(6), 'created_at' => $today->copy()->subDays(6)]);

    // Out-of-window won deal — must be excluded
    Deal::create(['title' => 'WonOld', 'closed_status' => 'won', 'closed_at' => $today->copy()->subYear(), 'created_at' => $today->copy()->subYear()]);

    $method = new ReflectionMethod(DealStatusDoughnutChart::class, 'getData');
    $method->setAccessible(true);
    $data = $method->invoke(new DealStatusDoughnutChart);

    // slices: [open, won, lost]
    expect($data['datasets'][0]['data'][0])->toBe(2); // open
    expect($data['datasets'][0]['data'][1])->toBe(1); // won (in window)
    expect($data['datasets'][0]['data'][2])->toBe(2); // lost
});
