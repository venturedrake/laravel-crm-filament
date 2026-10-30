<?php

use Filament\Panel;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\TableWidget;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\Pipeline;
use VentureDrake\LaravelCrm\Models\PipelineStage;
use VentureDrake\LaravelCrmFilament\LaravelCrmPlugin;
use VentureDrake\LaravelCrmFilament\Widgets\ContactsStatsOverview;
use VentureDrake\LaravelCrmFilament\Widgets\CrmStatsOverview;
use VentureDrake\LaravelCrmFilament\Widgets\DealsPipelineValueChart;
use VentureDrake\LaravelCrmFilament\Widgets\DealStatusDoughnutChart;
use VentureDrake\LaravelCrmFilament\Widgets\DealsValueStat;
use VentureDrake\LaravelCrmFilament\Widgets\LeadsByStageChart;
use VentureDrake\LaravelCrmFilament\Widgets\LeadsVsDealsChart;
use VentureDrake\LaravelCrmFilament\Widgets\MonthlyRevenueChart;
use VentureDrake\LaravelCrmFilament\Widgets\RecentActivityList;
use VentureDrake\LaravelCrmFilament\Widgets\TasksDueTodayList;

dataset('widgets', [
    'CrmStatsOverview' => [CrmStatsOverview::class, StatsOverviewWidget::class],
    'DealsValueStat' => [DealsValueStat::class, StatsOverviewWidget::class],
    'ContactsStatsOverview' => [ContactsStatsOverview::class, StatsOverviewWidget::class],
    'LeadsByStageChart' => [LeadsByStageChart::class, ChartWidget::class],
    'LeadsVsDealsChart' => [LeadsVsDealsChart::class, ChartWidget::class],
    'DealsPipelineValueChart' => [DealsPipelineValueChart::class, ChartWidget::class],
    'DealStatusDoughnutChart' => [DealStatusDoughnutChart::class, ChartWidget::class],
    'MonthlyRevenueChart' => [MonthlyRevenueChart::class, ChartWidget::class],
    'TasksDueTodayList' => [TasksDueTodayList::class, TableWidget::class],
    'RecentActivityList' => [RecentActivityList::class, TableWidget::class],
]);

it('each dashboard widget extends the expected Filament widget base', function (string $widget, string $base) {
    expect(is_subclass_of($widget, $base))->toBeTrue();
})->with('widgets');

it('table widgets declare a non-empty heading', function () {
    $recent = new ReflectionClass(RecentActivityList::class);
    expect($recent->getStaticPropertyValue('heading'))
        ->toBeString()
        ->not->toBeEmpty();

    $tasks = new TasksDueTodayList;
    expect($tasks->getHeading())
        ->toBe(__('laravel-crm-filament::labels.dashboard.upcoming_tasks'))
        ->not->toBeEmpty();
});

it('stats widgets declare a non-empty heading', function () {
    foreach ([CrmStatsOverview::class, DealsValueStat::class, ContactsStatsOverview::class] as $widget) {
        $instance = new $widget;
        expect($instance->getHeading())->toBeString()->not->toBeEmpty();
    }
});

it('TasksDueTodayList renders its translated heading rather than the class name', function () {
    $ref = new ReflectionMethod(TasksDueTodayList::class, 'getTableHeading');
    $ref->setAccessible(true);

    expect($ref->invoke(new TasksDueTodayList))
        ->toBe(__('laravel-crm-filament::labels.dashboard.upcoming_tasks'));
});

it('orders panel widgets so a stock Filament dashboard renders the curated layout', function () {
    $panel = Panel::make()->id('widget-sort-' . uniqid());
    LaravelCrmPlugin::make()->register($panel);

    expect(array_values($panel->getWidgets()))->toBe([
        CrmStatsOverview::class,
        DealsValueStat::class,
        ContactsStatsOverview::class,
        MonthlyRevenueChart::class,
        DealsPipelineValueChart::class,
        LeadsVsDealsChart::class,
        DealStatusDoughnutChart::class,
        LeadsByStageChart::class,
        TasksDueTodayList::class,
        RecentActivityList::class,
    ]);
});

it('LeadsByStageChart only charts stages from lead pipelines', function () {
    $leads = Pipeline::create(['name' => 'Leads', 'model' => Lead::class, 'order' => 0]);
    $invoices = Pipeline::create(['name' => 'Invoices', 'model' => Invoice::class, 'order' => 1]);
    PipelineStage::create(['name' => 'New', 'pipeline_id' => $leads->id, 'order' => 0]);
    PipelineStage::create(['name' => 'Paid', 'pipeline_id' => $invoices->id, 'order' => 1]);

    $ref = new ReflectionMethod(LeadsByStageChart::class, 'getData');
    $ref->setAccessible(true);

    expect($ref->invoke(new LeadsByStageChart)['labels'])->toBe(['New']);
});
