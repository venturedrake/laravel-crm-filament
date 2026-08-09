<?php

use Filament\Panel;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use VentureDrake\LaravelCrm\Models\SmsCampaign;
use VentureDrake\LaravelCrmFilament\LaravelCrmPlugin;
use VentureDrake\LaravelCrmFilament\Widgets\SmsCampaignStatsWidget;

it('extends StatsOverviewWidget', function () {
    expect(is_subclass_of(SmsCampaignStatsWidget::class, StatsOverviewWidget::class))->toBeTrue();
});

it('declares columnSpan = full', function () {
    $ref = new ReflectionProperty(SmsCampaignStatsWidget::class, 'columnSpan');
    $ref->setAccessible(true);

    $widget = (new ReflectionClass(SmsCampaignStatsWidget::class))->newInstanceWithoutConstructor();
    expect($ref->getValue($widget))->toBe('full');
});

it('declares public ?SmsCampaign $record = null property', function () {
    $ref = new ReflectionProperty(SmsCampaignStatsWidget::class, 'record');

    expect($ref->isPublic())->toBeTrue();
    expect($ref->getType()?->getName())->toBe(SmsCampaign::class);
    expect($ref->getType()?->allowsNull())->toBeTrue();

    $widget = (new ReflectionClass(SmsCampaignStatsWidget::class))->newInstanceWithoutConstructor();
    expect($ref->getValue($widget))->toBeNull();
});

it('getColumns() returns 4', function () {
    $widget = (new ReflectionClass(SmsCampaignStatsWidget::class))->newInstanceWithoutConstructor();

    $ref = new ReflectionMethod(SmsCampaignStatsWidget::class, 'getColumns');
    $ref->setAccessible(true);

    expect($ref->invoke($widget))->toBe(4);
});

it('getStats() returns exactly 4 Stat instances in Recipients / Delivered / Clicks / Unsubscribed order with em-dashes when record is null', function () {
    $widget = (new ReflectionClass(SmsCampaignStatsWidget::class))->newInstanceWithoutConstructor();

    $ref = new ReflectionMethod(SmsCampaignStatsWidget::class, 'getStats');
    $ref->setAccessible(true);

    $stats = $ref->invoke($widget);

    expect($stats)->toHaveCount(4);
    foreach ($stats as $stat) {
        expect($stat)->toBeInstanceOf(Stat::class);
    }

    // Labels in order (resolved at construction time)
    expect($stats[0]->getLabel())->toBe('Recipients');
    expect($stats[1]->getLabel())->toBe('Delivered');
    expect($stats[2]->getLabel())->toBe('Clicks');
    expect($stats[3]->getLabel())->toBe('Unsubscribed');

    // Null record → em-dash placeholders for each stat value
    expect($stats[0]->getValue())->toBe('—');
    expect($stats[1]->getValue())->toBe('—');
    expect($stats[2]->getValue())->toBe('—');
    expect($stats[3]->getValue())->toBe('—');
});

it('getStats() renders totals and rate descriptions when record is hydrated', function () {
    $campaign = new SmsCampaign;
    $campaign->total_recipients = 100;
    $campaign->delivered_count = 92;
    $campaign->unique_clicks_count = 25;
    $campaign->unsubscribes_count = 4;

    $widget = (new ReflectionClass(SmsCampaignStatsWidget::class))->newInstanceWithoutConstructor();
    $widget->record = $campaign;

    $ref = new ReflectionMethod(SmsCampaignStatsWidget::class, 'getStats');
    $ref->setAccessible(true);

    $stats = $ref->invoke($widget);

    expect($stats[0]->getValue())->toBe('100');
    expect($stats[1]->getValue())->toBe('92');
    expect($stats[2]->getValue())->toBe('25');
    expect($stats[3]->getValue())->toBe('4');

    // Descriptions carry the rate percentage from the SmsCampaign helpers.
    expect($stats[1]->getDescription())->toContain('92%');
    expect($stats[2]->getDescription())->toContain('25%');
    expect($stats[3]->getDescription())->toContain('4%');
});

it('source references only campaign.* translation keys (no new keys added)', function () {
    $source = file_get_contents((new ReflectionClass(SmsCampaignStatsWidget::class))->getFileName());

    expect($source)->toContain('laravel-crm-filament::labels.campaign.recipients');
    expect($source)->toContain('laravel-crm-filament::labels.campaign.delivered');
    expect($source)->toContain('laravel-crm-filament::labels.campaign.clicks');
    expect($source)->toContain('laravel-crm-filament::labels.campaign.unsubscribed');
    expect($source)->toContain('laravel-crm-filament::labels.campaign.delivery_rate');
    expect($source)->toContain('laravel-crm-filament::labels.campaign.click_rate');
    expect($source)->toContain('laravel-crm-filament::labels.campaign.unsubscribe_rate');
});

it('imports SmsCampaign, StatsOverviewWidget, and Stat at the top of the source', function () {
    $source = file_get_contents((new ReflectionClass(SmsCampaignStatsWidget::class))->getFileName());

    expect($source)->toContain('use VentureDrake\\LaravelCrm\\Models\\SmsCampaign;');
    expect($source)->toContain('use Filament\\Widgets\\StatsOverviewWidget;');
    expect($source)->toContain('use Filament\\Widgets\\StatsOverviewWidget\\Stat;');
});

it('lives under the VentureDrake\\LaravelCrmFilament\\Widgets namespace', function () {
    $ref = new ReflectionClass(SmsCampaignStatsWidget::class);
    expect($ref->getNamespaceName())->toBe('VentureDrake\\LaravelCrmFilament\\Widgets');
});

it('is NOT registered on the panel — referenced directly by ViewSmsCampaign::getHeaderWidgets() so it never surfaces on the Dashboard via Filament::getWidgets()', function () {
    $plugin = LaravelCrmPlugin::make();
    $panel = Panel::make()->id('sms-stats-not-registered-' . uniqid());
    $plugin->register($panel);

    expect($panel->getWidgets())->not->toContain(SmsCampaignStatsWidget::class);
});
