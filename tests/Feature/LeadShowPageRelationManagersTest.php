<?php

use VentureDrake\LaravelCrmFilament\RelationManagers\CrmCallsRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmFilesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmLunchesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmMeetingsRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmNotesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmTasksRelationManager;
use VentureDrake\LaravelCrmFilament\Resources\Leads\LeadResource;

/**
 * Comprehensive regression gate for the Lead show-page relation-manager redesign
 * (lead notes UI + lead card-styles series, US-001..US-006). Locks the AC contracts
 * in one focused file:
 *   (a) All 6 Lead-prefixed RM classes are in LeadResource::getRelations() in the
 *       AC-named tab order (Notes / Tasks / Calls / Meetings / Lunches / Files).
 *   (b) Each subclass declares a LOCAL $view property pointing at
 *       'laravel-crm-filament::lead-{x}'.
 *   (c) Each Blade view file exists and @includes the shared
 *       'laravel-crm-filament::partials.crm-card-styles' partial.
 *   (d) Dark-mode CSS markers (--crm-card-bg, var(--color-gray-900)) exist in the
 *       shared partial.
 *   (e) Each view contains the expected structural markers (heading, add card,
 *       cards loop, 3-dot dropdown).
 */
function leadShowPageRmMatrix(): array
{
    return [
        // [class, slug, blade-filename, section-translation-key, add-card-testid, wrapper-class]
        'Notes' => [CrmNotesRelationManager::class, 'crm-notes', 'crm-notes.blade.php', 'sections.add_note', 'crm-lead-note-add-card', 'crm-card-area-notes'],
        'Tasks' => [CrmTasksRelationManager::class, 'crm-tasks', 'crm-tasks.blade.php', 'sections.add_task', 'crm-lead-task-add-card', 'crm-card-area-tasks'],
        'Calls' => [CrmCallsRelationManager::class, 'crm-calls', 'crm-calls.blade.php', 'sections.add_call', 'crm-lead-call-add-card', 'crm-card-area-calls'],
        'Meetings' => [CrmMeetingsRelationManager::class, 'crm-meetings', 'crm-meetings.blade.php', 'sections.add_meeting', 'crm-lead-meeting-add-card', 'crm-card-area-meetings'],
        'Lunches' => [CrmLunchesRelationManager::class, 'crm-lunches', 'crm-lunches.blade.php', 'sections.add_lunch', 'crm-lead-lunch-add-card', 'crm-card-area-lunches'],
        'Files' => [CrmFilesRelationManager::class, 'crm-files', 'crm-files.blade.php', 'sections.add_file', 'crm-lead-file-add-card', 'crm-card-area-files'],
    ];
}

function leadShowPageBladeRoot(): string
{
    return dirname(__DIR__, 2) . '/resources/views';
}

it('AC(a): registers all 6 Lead-prefixed RM classes in LeadResource::getRelations() in the AC-named tab order', function () {
    $relations = LeadResource::getRelations();

    // The six Lead-prefixed RMs land at indices 1..6 (index 0 is ActivitiesRelationManager
    // per the v0.x US-008 lead show-page series wiring; index 7+ does not exist).
    expect($relations[1])->toBe(CrmNotesRelationManager::class)
        ->and($relations[2])->toBe(CrmTasksRelationManager::class)
        ->and($relations[3])->toBe(CrmCallsRelationManager::class)
        ->and($relations[4])->toBe(CrmMeetingsRelationManager::class)
        ->and($relations[5])->toBe(CrmLunchesRelationManager::class)
        ->and($relations[6])->toBe(CrmFilesRelationManager::class);
});

it('AC(b): each Lead-prefixed RM subclass declares a LOCAL $view property pointing at the expected blade key', function (string $class, string $slug) {
    $reflection = new ReflectionClass($class);

    expect($reflection->hasProperty('view'))->toBeTrue();

    $property = $reflection->getProperty('view');

    // Locally declared (not inherited from the Filament base RelationManager).
    expect($property->getDeclaringClass()->getName())->toBe($class);

    // Value matches the AC-named blade key.
    $instance = $reflection->newInstanceWithoutConstructor();
    $property->setAccessible(true);
    expect($property->getValue($instance))->toBe('laravel-crm-filament::' . $slug);
})->with([
    'Notes' => [CrmNotesRelationManager::class, 'crm-notes'],
    'Tasks' => [CrmTasksRelationManager::class, 'crm-tasks'],
    'Calls' => [CrmCallsRelationManager::class, 'crm-calls'],
    'Meetings' => [CrmMeetingsRelationManager::class, 'crm-meetings'],
    'Lunches' => [CrmLunchesRelationManager::class, 'crm-lunches'],
    'Files' => [CrmFilesRelationManager::class, 'crm-files'],
]);

it('AC(c): each Lead-prefixed blade view file exists and @includes the shared lead-card-styles partial', function (string $bladeFile) {
    $path = leadShowPageBladeRoot() . '/' . $bladeFile;

    expect(file_exists($path))->toBeTrue();

    $source = file_get_contents($path);
    expect($source)->toContain("@include('laravel-crm-filament::partials.crm-card-styles')");
})->with([
    'crm-notes.blade.php',
    'crm-tasks.blade.php',
    'crm-calls.blade.php',
    'crm-meetings.blade.php',
    'crm-lunches.blade.php',
    'crm-files.blade.php',
]);

it('AC(d): shared lead-card-styles partial declares dark-mode CSS markers (--crm-card-bg, var(--color-gray-900))', function () {
    $path = leadShowPageBladeRoot() . '/partials/crm-card-styles.blade.php';

    expect(file_exists($path))->toBeTrue();

    $source = file_get_contents($path);

    // The light-mode block declares --crm-card-bg as a CSS custom property.
    expect($source)->toContain('--crm-card-bg:');

    // The dark-mode block reads from Tailwind's --color-gray-900 variable.
    expect($source)->toContain('var(--color-gray-900');

    // The partial is wrapped in @once / @endonce so it emits at most one <style> per page.
    expect($source)->toContain('@once')
        ->and($source)->toContain('@endonce')
        ->and($source)->toContain('<style>')
        ->and($source)->toContain('</style>');

    // Each of the six wrapper classes is targeted by both light-mode and dark-mode rules.
    foreach (['crm-card-area-notes', 'crm-card-area-tasks', 'crm-card-area-calls', 'crm-card-area-meetings', 'crm-card-area-lunches', 'crm-card-area-files'] as $wrapper) {
        expect($source)->toContain('.' . $wrapper)
            ->and($source)->toContain('html.dark .' . $wrapper);
    }
});

it('AC(e): each Lead-prefixed blade view contains the expected structural markers', function (string $bladeFile, string $sectionKey, string $addCardTestId, string $wrapperClass) {
    $path = leadShowPageBladeRoot() . '/' . $bladeFile;
    $source = file_get_contents($path);

    // (1) Wrapper class on the outer <div> — drives the scoped CSS custom properties.
    expect($source)->toContain('class="' . $wrapperClass . '"');

    // (2) Section heading: an <h3 class="crm-card-section-heading"> bearing the AC-named translation key.
    expect($source)->toContain('crm-card-section-heading')
        ->and($source)->toContain("__('laravel-crm-filament::labels." . $sectionKey . "')");

    // (3) Add card: the always-visible (Files) or @if-gated (Notes/Tasks/Calls/Meetings/Lunches)
    //     card wrapped in crm-card-card crm-card-card--add with the AC-named data-testid.
    expect($source)->toContain('crm-card-card crm-card-card--add')
        ->and($source)->toContain('data-testid="' . $addCardTestId . '"');

    // (4) Cards loop: a @forelse over a $rows local with @empty fallback.
    // US-009: the created_at-desc ordering moved into
    // RollsUpRelatedActivity::relatedActivityRows(), which every card view
    // now sources its rows from.
    expect($source)->toContain('@forelse')
        ->and($source)->toContain('@empty')
        ->and($source)->toContain('$this->relatedActivityRows()');

    // (5) Three-dot dropdown: Alpine-driven open/close state, the dropdown wrapper class,
    //     the trigger button class, and the dropdown menu container class — all four are
    //     required for the dropdown to work both visually and functionally.
    expect($source)->toContain('x-data="{ open: false }"')
        ->and($source)->toContain('crm-card-dropdown')
        ->and($source)->toContain('crm-card-dropdown-btn')
        ->and($source)->toContain('crm-card-dropdown-menu');
})->with([
    'Notes' => ['crm-notes.blade.php', 'sections.add_note', 'crm-lead-note-add-card', 'crm-card-area-notes'],
    'Tasks' => ['crm-tasks.blade.php', 'sections.add_task', 'crm-lead-task-add-card', 'crm-card-area-tasks'],
    'Calls' => ['crm-calls.blade.php', 'sections.add_call', 'crm-lead-call-add-card', 'crm-card-area-calls'],
    'Meetings' => ['crm-meetings.blade.php', 'sections.add_meeting', 'crm-lead-meeting-add-card', 'crm-card-area-meetings'],
    'Lunches' => ['crm-lunches.blade.php', 'sections.add_lunch', 'crm-lead-lunch-add-card', 'crm-card-area-lunches'],
    'Files' => ['crm-files.blade.php', 'sections.add_file', 'crm-lead-file-add-card', 'crm-card-area-files'],
]);
