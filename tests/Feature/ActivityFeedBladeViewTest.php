<?php

function activityFeedBladeSource(): string
{
    $path = dirname(__DIR__, 2) . '/resources/views/activity-feed.blade.php';
    expect(file_exists($path))->toBeTrue('activity-feed.blade.php should exist');

    return (string) file_get_contents($path);
}

function crmActivityBladeSource(): string
{
    $path = dirname(__DIR__, 2) . '/resources/views/crm-activity.blade.php';

    return (string) file_get_contents($path);
}

it('wraps the activity-feed view in <x-filament-panels::page>', function () {
    $src = activityFeedBladeSource();

    expect($src)->toContain('<x-filament-panels::page>')
        ->and($src)->toContain('</x-filament-panels::page>');
});

it('renders two scope buttons wired to setScope with the AC-named translation keys', function () {
    $src = activityFeedBladeSource();

    expect($src)->toContain("wire:click=\"setScope('mine')\"")
        ->and($src)->toContain("wire:click=\"setScope('all')\"")
        ->and($src)->toContain("__('laravel-crm-filament::labels.sales.my_activity')")
        ->and($src)->toContain("__('laravel-crm-filament::labels.sales.all_activity')");
});

it('drives scope button active state from the $scope property', function () {
    $src = activityFeedBladeSource();

    expect($src)->toContain("\$scope === 'mine'")
        ->and($src)->toContain("\$scope === 'all'");
});

it('renders seven tab buttons in the AC-named order iterating the tabs array', function () {
    $src = activityFeedBladeSource();

    // The tab list literal must be present in the AC-named order.
    expect($src)->toContain("['all', 'notes', 'tasks', 'calls', 'meetings', 'lunches', 'files']");

    // The tab loop emits wire:click="setTab('...')" via the loop variable.
    expect($src)->toContain("wire:click=\"setTab('{{ \$t }}')\"");

    // Loop iteration variable.
    expect($src)->toContain('@foreach ($tabs as $t)');
});

it('applies an active class driven by $tab === $t on tab buttons', function () {
    $src = activityFeedBladeSource();

    expect($src)->toContain('$tab === $t');
});

it('renders a crm-timeline container that @foreachs $this->getActivities()', function () {
    $src = activityFeedBladeSource();

    expect($src)->toContain('data-testid="crm-timeline"')
        ->and($src)->toContain('$this->getActivities()')
        ->and($src)->toContain('@forelse ($activities as $activity)');
});

it('includes crm-activity per row with activity and last variables', function () {
    $src = activityFeedBladeSource();

    expect($src)->toContain("@include('laravel-crm-filament::crm-activity', ['activity' => \$activity, 'last' => \$loop->last])");
});

it('emits pagination links via $activities->links()', function () {
    $src = activityFeedBladeSource();

    expect($src)->toContain('$activities->links()');
});

it('does not introduce any new blade partials beyond activity-feed.blade.php', function () {
    // Count blade files inside resources/views/partials to lock the AC's
    // "No new blade partials are introduced beyond this one file" contract.
    // The AC allows the top-level activity-feed.blade.php file; the partials
    // directory must remain at its pre-US-004 population of 7 files.
    $partialsDir = dirname(__DIR__, 2) . '/resources/views/partials';
    $files = glob($partialsDir . '/*.blade.php') ?: [];
    $names = array_map(fn ($p) => basename($p), $files);

    expect($names)->toBe([
        'crm-card-call.blade.php',
        'crm-card-file.blade.php',
        'crm-card-lunch.blade.php',
        'crm-card-meeting.blade.php',
        'crm-card-note.blade.php',
        'crm-card-styles.blade.php',
        'crm-card-task.blade.php',
        'crm-related-badge.blade.php',
    ]);
});

it('crm-activity supports single-item mode via activity + last variables', function () {
    $src = crmActivityBladeSource();

    // Single-item mode branch is present.
    expect($src)->toContain('isset($activity)')
        ->and($src)->toContain('crm-timeline-item');

    // The per-type includes are preserved for reuse.
    foreach (['crm-card-note', 'crm-card-task', 'crm-card-call', 'crm-card-meeting', 'crm-card-lunch', 'crm-card-file'] as $partial) {
        expect($src)->toContain("laravel-crm-filament::partials.{$partial}");
    }
});
