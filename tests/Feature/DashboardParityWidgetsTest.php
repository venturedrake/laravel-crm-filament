<?php

use Filament\Widgets\ChartWidget;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use VentureDrake\LaravelCrm\Models\Deal;
use VentureDrake\LaravelCrm\Models\Pipeline;
use VentureDrake\LaravelCrm\Models\PipelineStage;
use VentureDrake\LaravelCrmFilament\Concerns\HasChartRangeFilter;
use VentureDrake\LaravelCrmFilament\Widgets\ContactsStatsOverview;
use VentureDrake\LaravelCrmFilament\Widgets\DealsPipelineValueChart;
use VentureDrake\LaravelCrmFilament\Widgets\DealStatusDoughnutChart;
use VentureDrake\LaravelCrmFilament\Widgets\LeadsVsDealsChart;

// ----------------------------------------------------------------------------
// Period-filterable charts: LeadsVsDealsChart + DealStatusDoughnutChart
// ----------------------------------------------------------------------------

dataset('dashboard_parity_period_charts', [
    'LeadsVsDealsChart' => [LeadsVsDealsChart::class, 'last_30_days'],
    'DealStatusDoughnutChart' => [DealStatusDoughnutChart::class, 'last_30_days'],
]);

it('period-filterable dashboard widget extends ChartWidget', function (string $class) {
    expect(is_subclass_of($class, ChartWidget::class))->toBeTrue();
})->with('dashboard_parity_period_charts');

it('period-filterable dashboard widget uses HasChartRangeFilter trait', function (string $class) {
    expect(class_uses_recursive($class))->toContain(HasChartRangeFilter::class);
})->with('dashboard_parity_period_charts');

it('period-filterable dashboard widget defaults $filter to the expected value', function (string $class, string $expected) {
    expect((new $class)->filter)->toBe($expected);
})->with('dashboard_parity_period_charts');

it('period-filterable dashboard widget exposes the 13 period filter keys', function (string $class) {
    $widget = new $class;
    $ref = new ReflectionMethod($class, 'getFilters');
    $ref->setAccessible(true);
    $filters = $ref->invoke($widget);

    expect(array_keys($filters))->toBe([
        'today',
        'yesterday',
        'last_7_days',
        'last_30_days',
        'last_90_days',
        'last_365_days',
        'this_month',
        'last_month',
        'this_quarter',
        'last_quarter',
        'this_year',
        'last_year',
        'all_time',
    ]);
})->with('dashboard_parity_period_charts');

it('period-filterable dashboard widget getData() returns datasets + labels shape', function (string $class) {
    $widget = new $class;
    $ref = new ReflectionMethod($class, 'getData');
    $ref->setAccessible(true);

    $data = $ref->invoke($widget);

    expect($data)->toHaveKeys(['datasets', 'labels']);
    expect($data['datasets'])->toBeArray();
    expect($data['labels'])->toBeArray();
})->with('dashboard_parity_period_charts');

// ----------------------------------------------------------------------------
// DealsPipelineValueChart — timeless (no period filter)
// ----------------------------------------------------------------------------

it('DealsPipelineValueChart extends ChartWidget', function () {
    expect(is_subclass_of(DealsPipelineValueChart::class, ChartWidget::class))->toBeTrue();
});

it('DealsPipelineValueChart getFilters() returns null (timeless)', function () {
    $ref = new ReflectionMethod(DealsPipelineValueChart::class, 'getFilters');
    $ref->setAccessible(true);

    expect($ref->invoke(new DealsPipelineValueChart))->toBeNull();
});

it('DealsPipelineValueChart getData() returns datasets + labels shape iterating seeded PipelineStage rows', function () {
    $pipeline = Pipeline::create([
        'name' => 'Sales',
        'model' => Deal::class,
        'order' => 0,
    ]);
    PipelineStage::create(['name' => 'Prospect', 'pipeline_id' => $pipeline->id, 'order' => 0]);
    PipelineStage::create(['name' => 'Qualified', 'pipeline_id' => $pipeline->id, 'order' => 1]);
    PipelineStage::create(['name' => 'Won', 'pipeline_id' => $pipeline->id, 'order' => 2]);

    $ref = new ReflectionMethod(DealsPipelineValueChart::class, 'getData');
    $ref->setAccessible(true);
    $data = $ref->invoke(new DealsPipelineValueChart);

    expect($data)->toHaveKeys(['datasets', 'labels']);
    expect($data['datasets'])->toHaveCount(1);
    expect($data['datasets'][0])->toHaveKey('data');
    expect($data['datasets'][0]['data'])->toHaveCount(3);
    // Labels come from stages ordered by `order` ASC.
    expect($data['labels'])->toBe(['Prospect', 'Qualified', 'Won']);
});

// ----------------------------------------------------------------------------
// ContactsStatsOverview — StatsOverviewWidget (not a ChartWidget)
// ----------------------------------------------------------------------------

it('ContactsStatsOverview extends StatsOverviewWidget', function () {
    expect(is_subclass_of(ContactsStatsOverview::class, StatsOverviewWidget::class))->toBeTrue();
});

it('ContactsStatsOverview getStats() returns 3 Stats on empty DB', function () {
    $ref = new ReflectionMethod(ContactsStatsOverview::class, 'getStats');
    $ref->setAccessible(true);
    $stats = $ref->invoke(new ContactsStatsOverview);

    expect($stats)->toBeArray()->toHaveCount(3);
    expect($stats)->each->toBeInstanceOf(Stat::class);
});
