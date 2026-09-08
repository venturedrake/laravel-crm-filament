<?php

use Filament\Resources\Pages\Page;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrmFilament\Resources\Features\FeatureResource;
use VentureDrake\LaravelCrmFilament\Resources\Features\Pages\FeatureKanban;

it('declares FeatureKanban as a sub-resource page bound to FeatureResource', function () {
    $resourceProp = new ReflectionProperty(FeatureKanban::class, 'resource');
    expect($resourceProp->getValue())->toBe(FeatureResource::class);
});

it('extends Filament\Resources\Pages\Page', function () {
    expect(is_subclass_of(FeatureKanban::class, Page::class))->toBeTrue();
});

it('registers the kanban route on FeatureResource::getPages()', function () {
    expect(FeatureResource::getPages())->toHaveKey('kanban');
});

it('exposes a moveFeature(string, int) Livewire entry point', function () {
    expect(method_exists(FeatureKanban::class, 'moveFeature'))->toBeTrue();
    $reflection = new ReflectionMethod(FeatureKanban::class, 'moveFeature');
    expect($reflection->isPublic())->toBeTrue();
    expect($reflection->getNumberOfRequiredParameters())->toBe(2);
    $params = $reflection->getParameters();
    expect($params[0]->getName())->toBe('externalId');
    expect((string) $params[0]->getType())->toBe('string');
    expect($params[1]->getName())->toBe('statusId');
    expect((string) $params[1]->getType())->toBe('int');
});

it('renders the laravel-crm-filament::features.kanban view', function () {
    $viewProp = new ReflectionProperty(FeatureKanban::class, 'view');
    $viewProp->setAccessible(true);
    $instance = (new ReflectionClass(FeatureKanban::class))->newInstanceWithoutConstructor();
    expect($viewProp->getValue($instance))->toBe('laravel-crm-filament::features.kanban');
});

it('exposes getStatuses() ordering FeatureStatus by order ASC', function () {
    $src = file_get_contents((new ReflectionClass(FeatureKanban::class))->getFileName());
    expect($src)->toContain('FeatureStatus::query()');
    expect($src)->toContain("->orderBy('order')");
});

it('exposes getFeaturesByStatus() grouping by feature_status_id', function () {
    $src = file_get_contents((new ReflectionClass(FeatureKanban::class))->getFileName());
    expect($src)->toContain("groupBy('feature_status_id')");
    expect($src)->toContain("whereNotNull('feature_status_id')");
});

it('does NOT use Pipeline indirection (Features do not use Pipeline)', function () {
    $src = file_get_contents((new ReflectionClass(FeatureKanban::class))->getFileName());
    expect($src)->not->toContain('Pipeline');
    expect($src)->not->toContain('PipelineStage');
});

it('moveFeature persists feature_status_id when given a valid external_id', function () {
    actingAsCrmAdmin();

    if (! class_exists('VentureDrake\\LaravelCrm\\Models\\Feature')) {
        $this->markTestSkipped('Feature model not present in vendor lock; integration test requires upstream model.');
    }

    $featureClass = 'VentureDrake\\LaravelCrm\\Models\\Feature';
    $statusClass = 'VentureDrake\\LaravelCrm\\Models\\FeatureStatus';

    $statusOne = $statusClass::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Planned',
        'order' => 1,
    ]);
    $statusTwo = $statusClass::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'In progress',
        'order' => 2,
    ]);

    $feature = $featureClass::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Test feature',
        'feature_status_id' => $statusOne->id,
    ]);

    $kanban = new FeatureKanban;
    $kanban->moveFeature($feature->external_id, (int) $statusTwo->id);

    expect($feature->fresh()->feature_status_id)->toBe((int) $statusTwo->id);
});

it('moveFeature is a no-op when external_id does not resolve', function () {
    if (! class_exists('VentureDrake\\LaravelCrm\\Models\\Feature')) {
        $this->markTestSkipped('Feature model not present in vendor lock.');
    }

    $kanban = new FeatureKanban;
    // Should not throw, just return.
    $kanban->moveFeature('non-existent-external-id', 1);
    expect(true)->toBeTrue();
});

it('kanban blade view exists at the expected path with status name + color and no convert-to-deal button', function () {
    $view = dirname(__DIR__, 2) . '/resources/views/features/kanban.blade.php';
    expect(file_exists($view))->toBeTrue();

    $blade = file_get_contents($view);
    expect($blade)->toContain('$this->getStatuses()');
    expect($blade)->toContain('$this->getFeaturesByStatus()');
    expect($blade)->toContain('data-status-id');
    expect($blade)->toContain('data-feature-id');
    expect($blade)->toContain('$status->name');
    expect($blade)->toContain('$status->color');
    expect($blade)->toContain('moveFeature');

    // AC: drop the convert-to-deal button from the Lead kanban.
    expect($blade)->not->toContain('convertToDeal');
    expect($blade)->not->toContain('Convert to deal');
});
