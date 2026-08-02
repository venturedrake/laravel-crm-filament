<?php

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Contact;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\Note;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Services\SettingService;
use VentureDrake\LaravelCrmFilament\Concerns\RollsUpRelatedActivity;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmActivitiesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmCallsRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmFilesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmLunchesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmMeetingsRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmNotesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmTasksRelationManager;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\Pages\ViewOrganization;

/**
 * US-009 — `show_related_activity` rollup.
 *
 * Base honours the setting across its activity components by looping every
 * related contact and firing a query per contact. The plugin ignored the
 * setting entirely; `RollsUpRelatedActivity` implements it as a single
 * `whereIn` over pre-collected morph pairs.
 */
function relatedActivitySetting(bool $enabled): void
{
    /** @var SettingService $settings */
    $settings = app('laravel-crm.settings');
    $settings->set('show_related_activity', $enabled ? 1 : 0);
    $settings->forgetCache();
}

function relatedActivityPerson(string $first = 'Related'): Person
{
    return Person::create([
        'external_id' => (string) Str::uuid(),
        'first_name' => $first,
        'last_name' => 'Contact ' . Str::random(6),
    ]);
}

function relatedActivityOrganization(string $name = 'Rollup Org'): Organization
{
    return Organization::create([
        'external_id' => (string) Str::uuid(),
        'name' => $name . ' ' . Str::random(6),
    ]);
}

function relatedActivityContact(object $owner, object $entity): Contact
{
    return Contact::create([
        'external_id' => (string) Str::uuid(),
        'contactable_type' => $owner->getMorphClass(),
        'contactable_id' => $owner->id,
        'entityable_type' => $entity->getMorphClass(),
        'entityable_id' => $entity->id,
    ]);
}

function relatedActivityNote(object $owner, string $content): Note
{
    return $owner->notes()->create([
        'external_id' => (string) Str::uuid(),
        'content' => $content,
        'noted_at' => now(),
    ]);
}

function relatedActivityNotesRm(object $owner): CrmNotesRelationManager
{
    $rm = new CrmNotesRelationManager;
    $rm->ownerRecord = $owner;

    return $rm;
}

it('applies the concern to all seven Crm relation managers', function (string $relationManager) {
    expect(class_uses_recursive($relationManager))->toContain(RollsUpRelatedActivity::class);
})->with([
    CrmActivitiesRelationManager::class,
    CrmCallsRelationManager::class,
    CrmFilesRelationManager::class,
    CrmLunchesRelationManager::class,
    CrmMeetingsRelationManager::class,
    CrmNotesRelationManager::class,
    CrmTasksRelationManager::class,
]);

it('reads the show_related_activity setting', function () {
    relatedActivitySetting(false);
    expect(CrmNotesRelationManager::showsRelatedActivity())->toBeFalse();

    relatedActivitySetting(true);
    expect(CrmNotesRelationManager::showsRelatedActivity())->toBeTrue();
});

it('shows only the record\'s own rows when the setting is off', function () {
    relatedActivitySetting(false);

    $organization = relatedActivityOrganization();
    $person = relatedActivityPerson();
    relatedActivityContact($organization, $person);

    relatedActivityNote($organization, 'Own note');
    relatedActivityNote($person, 'Related note');

    $rows = relatedActivityNotesRm($organization)->relatedActivityRows();

    expect($rows->pluck('content')->all())->toBe(['Own note']);
});

it('rolls a related contact\'s rows up to the parent record when the setting is on', function () {
    relatedActivitySetting(true);

    $organization = relatedActivityOrganization();
    $person = relatedActivityPerson();
    relatedActivityContact($organization, $person);

    relatedActivityNote($organization, 'Own note');
    relatedActivityNote($person, 'Related note');

    $rows = relatedActivityNotesRm($organization)->relatedActivityRows();

    expect($rows->pluck('content')->sort()->values()->all())
        ->toBe(['Own note', 'Related note']);
});

it('rolls the owner record\'s person and organization up when the setting is on', function () {
    relatedActivitySetting(true);

    $person = relatedActivityPerson();
    $organization = relatedActivityOrganization();

    $lead = Lead::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Rollup lead',
        'person_id' => $person->id,
        'organization_id' => $organization->id,
    ]);

    relatedActivityNote($lead, 'Lead note');
    relatedActivityNote($person, 'Person note');
    relatedActivityNote($organization, 'Organization note');

    $rows = relatedActivityNotesRm($lead)->relatedActivityRows();

    expect($rows->pluck('content')->sort()->values()->all())
        ->toBe(['Lead note', 'Organization note', 'Person note']);

    relatedActivitySetting(false);

    $rows = relatedActivityNotesRm($lead->fresh())->relatedActivityRows();

    expect($rows->pluck('content')->all())->toBe(['Lead note']);
});

it('excludes rows belonging to an unrelated record', function () {
    relatedActivitySetting(true);

    $organization = relatedActivityOrganization();
    $person = relatedActivityPerson();
    relatedActivityContact($organization, $person);

    $stranger = relatedActivityPerson('Stranger');
    relatedActivityNote($stranger, 'Stranger note');
    relatedActivityNote($organization, 'Own note');

    $rows = relatedActivityNotesRm($organization)->relatedActivityRows();

    expect($rows->pluck('content')->all())->not->toContain('Stranger note');
});

it('flags rolled-up rows as related and the record\'s own rows as not', function () {
    relatedActivitySetting(true);

    $organization = relatedActivityOrganization();
    $person = relatedActivityPerson();
    relatedActivityContact($organization, $person);

    $own = relatedActivityNote($organization, 'Own note');
    $related = relatedActivityNote($person, 'Related note');

    $rm = relatedActivityNotesRm($organization);

    expect($rm->isRelatedActivityRecord($own))->toBeFalse();
    expect($rm->isRelatedActivityRecord($related))->toBeTrue();
    expect($rm->isRelatedActivityRecord(null))->toBeFalse();
});

it('never treats the owner record as one of its own related entities', function () {
    relatedActivitySetting(true);

    $person = relatedActivityPerson();
    // A record can appear as its own contact; its rows are still its own.
    relatedActivityContact($person, $person);

    expect(relatedActivityNotesRm($person)->relatedActivityMorphPairs())->toBe([]);
});

it('de-duplicates repeated morph pairs', function () {
    relatedActivitySetting(true);

    $organization = relatedActivityOrganization();
    $person = relatedActivityPerson();
    relatedActivityContact($organization, $person);
    relatedActivityContact($organization, $person);

    $pairs = relatedActivityNotesRm($organization)->relatedActivityMorphPairs();

    expect($pairs)->toBe([$person->getMorphClass() => [(string) $person->id]]);
});

it('does not scale the query count with the number of related contacts', function () {
    relatedActivitySetting(true);

    DB::enableQueryLog();

    $count = function (int $relatedContacts): int {
        $organization = relatedActivityOrganization();
        relatedActivityNote($organization, 'Own note');

        for ($i = 0; $i < $relatedContacts; $i++) {
            $person = relatedActivityPerson('Related ' . $i);
            relatedActivityContact($organization, $person);
            relatedActivityNote($person, 'Related note ' . $i);
        }

        $rm = relatedActivityNotesRm($organization);

        DB::flushQueryLog();

        $rows = $rm->relatedActivityRows();

        $queries = count(DB::getQueryLog());

        expect($rows)->toHaveCount($relatedContacts + 1);

        return $queries;
    };

    $count(1); // Warm up anything the first query in a request pays for once.

    $one = $count(1);
    $many = $count(25);

    // Base fires one query per contact (plus one to load each entityable);
    // the rollup pre-collects the morph pairs and fires a fixed number.
    expect($many)->toBe($one);
    expect($many)->toBeLessThanOrEqual(3);
});

it('builds the rolled-up query as a single whereIn per morph type', function () {
    relatedActivitySetting(true);

    $organization = relatedActivityOrganization();

    for ($i = 0; $i < 5; $i++) {
        relatedActivityContact($organization, relatedActivityPerson('Bulk ' . $i));
    }

    $sql = relatedActivityNotesRm($organization)->rolledUpActivityQuery()->toSql();

    expect(substr_count(strtolower($sql), ' in ('))->toBe(2);
    expect($sql)->toContain('noteable_type');
});

it('falls back to the plain relationship query when the setting is off', function () {
    relatedActivitySetting(false);

    $organization = relatedActivityOrganization();
    relatedActivityContact($organization, relatedActivityPerson());

    $rm = relatedActivityNotesRm($organization);

    expect($rm->rolledUpActivityQuery())
        ->toBeInstanceOf(MorphMany::class);
});

it('falls back to the plain relationship query when there is nothing to roll up', function () {
    relatedActivitySetting(true);

    $organization = relatedActivityOrganization();

    expect(relatedActivityNotesRm($organization)->rolledUpActivityQuery())
        ->toBeInstanceOf(MorphMany::class);
});

it('exposes a Related badge column that is only visible while the setting is on', function () {
    $organization = relatedActivityOrganization();
    $rm = relatedActivityNotesRm($organization);

    relatedActivitySetting(true);
    $column = $rm->relatedActivityColumn();

    expect($column->getName())->toBe(CrmNotesRelationManager::RELATED_ACTIVITY_COLUMN);
    expect($column->isBadge())->toBeTrue();
    expect($column->isVisible())->toBeTrue();

    relatedActivitySetting(false);

    expect($rm->relatedActivityColumn()->isVisible())->toBeFalse();
});

it('renders the Related badge only for rolled-up rows', function () {
    relatedActivitySetting(true);

    $organization = relatedActivityOrganization();
    $person = relatedActivityPerson();
    relatedActivityContact($organization, $person);

    $own = relatedActivityNote($organization, 'Own note');
    $related = relatedActivityNote($person, 'Related note');

    $rm = relatedActivityNotesRm($organization);
    $rm->pageClass = ViewOrganization::class;
    $rm->bootedInteractsWithTable();

    // The badge column is pushed onto the relation manager's own table by the
    // concern's table() override, so read it back off the booted table.
    $column = $rm->getTable()->getColumn(CrmNotesRelationManager::RELATED_ACTIVITY_COLUMN);

    expect($column)->not->toBeNull();
    expect($column->record($related)->getState())->toBe(__('laravel-crm-filament::labels.fields.related'));
    expect($column->record($own)->getState())->toBeNull();
});

it('ships the Related label in every locale', function (string $locale) {
    $labels = require __DIR__ . '/../../resources/lang/' . $locale . '/labels.php';

    expect($labels['fields'])->toHaveKey('related');
    expect($labels['fields']['related'])->not->toBeEmpty();
})->with(['en', 'es', 'fr']);

it('sources the inline card views from the rollup rather than the raw relation', function (string $view, string $relation) {
    $source = file_get_contents(__DIR__ . '/../../resources/views/' . $view . '.blade.php');

    expect($source)->toContain('$this->relatedActivityRows()');
    expect($source)->not->toContain('->' . $relation . '()->orderBy');
    expect($source)->toContain('crm-related-badge');
})->with([
    ['crm-notes', 'notes'],
    ['crm-tasks', 'tasks'],
    ['crm-calls', 'calls'],
    ['crm-meetings', 'meetings'],
    ['crm-lunches', 'lunches'],
    ['crm-files', 'files'],
    ['crm-activity', 'timelineActivities'],
]);

it('rolls related rows into the relation manager table query', function () {
    $organization = relatedActivityOrganization();
    $person = relatedActivityPerson();
    relatedActivityContact($organization, $person);

    relatedActivityNote($organization, 'Own note');
    relatedActivityNote($person, 'Related note');

    relatedActivitySetting(false);

    $rm = relatedActivityNotesRm($organization);
    $rm->pageClass = ViewOrganization::class;
    $rm->bootedInteractsWithTable();

    expect($rm->getTable()->getQuery()->pluck('content')->all())->toBe(['Own note']);

    relatedActivitySetting(true);

    $rm = relatedActivityNotesRm($organization->fresh());
    $rm->pageClass = ViewOrganization::class;
    $rm->bootedInteractsWithTable();

    expect($rm->getTable()->getQuery()->pluck('content')->sort()->values()->all())
        ->toBe(['Own note', 'Related note']);
});
