<?php

use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Call;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\Lunch;
use VentureDrake\LaravelCrm\Models\Meeting;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrmFilament\RelationManagers\CallsRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmCallsRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmLunchesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmMeetingsRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\MeetingsRelationManager;
use VentureDrake\LaravelCrmFilament\Resources\Leads\Pages\EditLead;
use VentureDrake\LaravelCrmFilament\Resources\Leads\Pages\ViewLead;
use VentureDrake\LaravelCrmFilament\Support\EncryptedContactSearch;

use function Pest\Livewire\livewire;

/**
 * Core 2.7.0: a call, meeting or lunch needs a start and a finish, and the
 * finish can't be before the start (`after_or_equal:start_at`). The Guests
 * picker searches the server as the user types (20 results) rather than
 * loading every person into every form.
 */
beforeEach(function () {
    actingAsCrmAdmin();

    $this->lead = Lead::create(['external_id' => (string) Str::uuid(), 'title' => 'Engine', 'currency' => 'USD']);
});

dataset('crmActivityPanels', [
    'calls' => [CrmCallsRelationManager::class, 'createCall', Call::class],
    'meetings' => [CrmMeetingsRelationManager::class, 'createMeeting', Meeting::class],
    'lunches' => [CrmLunchesRelationManager::class, 'createLunch', Lunch::class],
]);

function activityPanel(string $manager, Lead $lead, string $page = ViewLead::class): mixed
{
    return livewire($manager, ['ownerRecord' => $lead, 'pageClass' => $page]);
}

function guestsSelect(mixed $component): Select
{
    /** @var Select $select */
    $select = $component->instance()->form->getFlatFields()['guests'];

    return $select;
}

it('refuses a finish before the start', function (string $manager, string $create, string $model) {
    activityPanel($manager, $this->lead)
        ->set('data.name', 'Kick-off')
        ->set('data.start_at', '2026-11-16 10:00:00')
        ->set('data.finish_at', '2026-11-16 09:30:00')
        ->call($create)
        ->assertHasErrors(['data.finish_at']);

    expect($model::query()->count())->toBe(0);
})->with('crmActivityPanels');

it('requires a finish', function (string $manager, string $create, string $model) {
    activityPanel($manager, $this->lead)
        ->set('data.name', 'Kick-off')
        ->set('data.start_at', '2026-11-16 10:00:00')
        ->set('data.finish_at', null)
        ->call($create)
        ->assertHasErrors(['data.finish_at' => 'required']);

    expect($model::query()->count())->toBe(0);
})->with('crmActivityPanels');

it('takes a finish at or after the start', function (string $manager, string $create, string $model) {
    activityPanel($manager, $this->lead)
        ->set('data.name', 'Kick-off')
        ->set('data.start_at', '2026-11-16 10:00:00')
        ->set('data.finish_at', '2026-11-16 10:00:00')
        ->call($create)
        ->assertHasNoErrors();

    expect($model::query()->count())->toBe(1);
})->with('crmActivityPanels');

it('refuses a finish before the start on the table-style call and meeting managers', function (string $manager, string $model) {
    // On an edit page, where these table managers aren't read-only.
    activityPanel($manager, $this->lead, EditLead::class)
        ->callAction(TestAction::make('create')->table(), data: [
            'name' => 'Kick-off',
            'start_at' => '2026-11-16 10:00:00',
            'finish_at' => '2026-11-16 09:00:00',
        ])
        ->assertHasActionErrors(['finish_at']);

    expect($model::query()->count())->toBe(0);
})->with([
    'calls' => [CallsRelationManager::class, Call::class],
    'meetings' => [MeetingsRelationManager::class, Meeting::class],
]);

describe('the guests picker', function () {
    beforeEach(function () {
        foreach (range(1, 25) as $i) {
            Person::create(['external_id' => (string) Str::uuid(), 'first_name' => 'Ada', 'last_name' => sprintf('Guest %02d', $i)]);
        }

        $this->grace = Person::create(['external_id' => (string) Str::uuid(), 'first_name' => 'Grace', 'last_name' => 'Hopper']);
    });

    it('preloads nobody and returns at most 20 matches', function (string $manager) {
        $select = guestsSelect(activityPanel($manager, $this->lead));

        expect($select->isPreloaded())->toBeFalse()
            ->and($select->getOptions())->toBe([])
            ->and($select->getSearchResults(''))->toBe([])
            ->and($select->getSearchResults('ada'))->toHaveCount(EncryptedContactSearch::GUEST_SEARCH_LIMIT)
            ->and($select->getSearchResults('grace hop'))->toBe([$this->grace->id => 'Grace Hopper']);
    })->with([CrmCallsRelationManager::class, CrmMeetingsRelationManager::class, CrmLunchesRelationManager::class]);

    it('labels the chosen guests', function () {
        $call = $this->lead->calls()->create([
            'name' => 'Kick-off',
            'start_at' => '2026-11-16 10:00:00',
            'finish_at' => '2026-11-16 11:00:00',
        ]);
        $call->contacts()->create(['entityable_type' => $this->grace->getMorphClass(), 'entityable_id' => $this->grace->id]);

        $component = activityPanel(CrmCallsRelationManager::class, $this->lead)->call('editCall', $call->id);

        expect(array_map('intval', array_values($component->get('data.guests'))))->toBe([$this->grace->id])
            ->and(guestsSelect($component)->getOptionLabels())->toBe([$this->grace->id => 'Grace Hopper']);
    });

    it('saves the guests picked from a search', function () {
        activityPanel(CrmCallsRelationManager::class, $this->lead)
            ->set('data.name', 'Kick-off')
            ->set('data.start_at', '2026-11-16 10:00:00')
            ->set('data.finish_at', '2026-11-16 11:00:00')
            ->set('data.guests', [$this->grace->id])
            ->call('createCall')
            ->assertHasNoErrors();

        expect(Call::query()->sole()->contacts()->pluck('entityable_id')->map(fn ($id) => (int) $id)->all())
            ->toBe([$this->grace->id]);
    });

    it('searches in SQL with encryption off, matching % literally', function () {
        Person::create(['external_id' => (string) Str::uuid(), 'first_name' => '100%', 'last_name' => 'Club']);

        expect(EncryptedContactSearch::personOptions('100%', 20, blankMatchesAll: false))->toHaveCount(1)
            ->and(EncryptedContactSearch::personOptions('%', 20, blankMatchesAll: false))->toHaveCount(1)
            ->and(EncryptedContactSearch::personOptions('Ada Guest 0', 5, blankMatchesAll: false))->toHaveCount(5);
    });

    it('still finds decrypted names with encryption on', function () {
        config(['laravel-crm.encrypt_db_fields' => true]);
        $encrypted = Person::create(['external_id' => (string) Str::uuid(), 'first_name' => 'Hedy', 'last_name' => 'Lamarr']);

        expect(EncryptedContactSearch::personOptions('hedy lam', 20, blankMatchesAll: false))->toBe([$encrypted->id => 'Hedy Lamarr'])
            ->and(EncryptedContactSearch::personLabels([$encrypted->id]))->toBe([$encrypted->id => 'Hedy Lamarr']);
    });
});
