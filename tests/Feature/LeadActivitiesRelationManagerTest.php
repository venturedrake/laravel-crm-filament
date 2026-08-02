<?php

use VentureDrake\LaravelCrmFilament\RelationManagers\ActivitiesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmActivitiesRelationManager;

it('extends ActivitiesRelationManager', function () {
    expect(is_subclass_of(CrmActivitiesRelationManager::class, ActivitiesRelationManager::class))->toBeTrue();
});

it('overrides the $view property to point at the lead-activity Blade template', function () {
    $ref = new ReflectionClass(CrmActivitiesRelationManager::class);
    $prop = $ref->getProperty('view');
    $prop->setAccessible(true);

    expect($prop->getDeclaringClass()->getName())->toBe(CrmActivitiesRelationManager::class);

    $rm = $ref->newInstanceWithoutConstructor();
    expect($prop->getValue($rm))->toBe('laravel-crm-filament::crm-activity');
});

it('inherits read-only contract from ActivitiesRelationManager', function () {
    $rm = (new ReflectionClass(CrmActivitiesRelationManager::class))->newInstanceWithoutConstructor();
    expect($rm->isReadOnly())->toBeTrue();
});

it('the lead-activity Blade view contains the expected timeline markers', function () {
    $bladePath = dirname(__DIR__, 2) . '/resources/views/crm-activity.blade.php';
    expect(file_exists($bladePath))->toBeTrue();

    $blade = file_get_contents($bladePath);

    // Wrapper class scopes the shared CSS custom properties.
    expect($blade)->toContain('class="crm-card-area-activity"');

    // Loop over the owner's timelineActivities, newest first — sourced from
    // RollsUpRelatedActivity::relatedActivityRows() since US-009.
    expect($blade)->toContain('$this->relatedActivityRows()');
    expect($blade)->toContain('@forelse');
    expect($blade)->toContain('@empty');

    // Timeline structural markers (rail + bullet + connector + body).
    expect($blade)->toContain('crm-timeline-item');
    expect($blade)->toContain('crm-timeline-rail');
    expect($blade)->toContain('crm-timeline-bullet');
    expect($blade)->toContain('crm-timeline-connector');
    expect($blade)->toContain('crm-timeline-body');
    expect($blade)->toContain('crm-timeline-title');
    expect($blade)->toContain('crm-timeline-subtitle');

    // Shared partial @include + no inline @once block (regression guard).
    expect($blade)->toContain("@include('laravel-crm-filament::partials.crm-card-styles')");
    expect($blade)->not->toContain('@once');

    // Empty state.
    expect($blade)->toContain('No activity yet');
});

it('the shared lead-card-styles partial declares timeline + .crm-card-area-activity scoping', function () {
    $partial = file_get_contents(dirname(__DIR__, 2) . '/resources/views/partials/crm-card-styles.blade.php');

    // Wrapper class participates in the existing CSS custom-property scope.
    expect($partial)->toContain('.crm-card-area-activity');
    expect($partial)->toContain('html.dark .crm-card-area-activity');

    // Timeline-specific selectors.
    expect($partial)->toContain('.crm-timeline-item');
    expect($partial)->toContain('.crm-timeline-rail');
    expect($partial)->toContain('.crm-timeline-bullet');
    expect($partial)->toContain('.crm-timeline-connector');
    expect($partial)->toContain('.crm-timeline-body');
    expect($partial)->toContain('.crm-timeline-title');
    expect($partial)->toContain('.crm-timeline-subtitle');
});

it('the timeline view embeds the per-entity card partials inside each recordable', function () {
    $bladePath = dirname(__DIR__, 2) . '/resources/views/crm-activity.blade.php';
    $blade = file_get_contents($bladePath);

    // The @switch over entityType routes each recordable to the matching partial.
    expect($blade)->toContain("@include('laravel-crm-filament::partials.crm-card-note'");
    expect($blade)->toContain("@include('laravel-crm-filament::partials.crm-card-task'");
    expect($blade)->toContain("@include('laravel-crm-filament::partials.crm-card-call'");
    expect($blade)->toContain("@include('laravel-crm-filament::partials.crm-card-meeting'");
    expect($blade)->toContain("@include('laravel-crm-filament::partials.crm-card-lunch'");
    expect($blade)->toContain("@include('laravel-crm-filament::partials.crm-card-file'");
});

it('each lead-card-{entity} partial file exists and renders the expected markers', function (string $partial, string $marker) {
    $path = dirname(__DIR__, 2) . '/resources/views/partials/' . $partial;
    expect(file_exists($path))->toBeTrue();

    $body = file_get_contents($path);
    // Every partial wraps the content in the .crm-card-card class and reads from $record.
    expect($body)->toContain('class="crm-card-card"');
    expect($body)->toContain('$record');
    // Per-entity marker locked in for grep safety.
    expect($body)->toContain($marker);
})->with([
    'note' => ['crm-card-note.blade.php', '$record->content'],
    'task' => ['crm-card-task.blade.php', '$record->name'],
    'call' => ['crm-card-call.blade.php', '$record->name'],
    'meeting' => ['crm-card-meeting.blade.php', '$record->name'],
    'lunch' => ['crm-card-lunch.blade.php', '$record->name'],
    'file' => ['crm-card-file.blade.php', '$record->file'],
]);
