<?php

use Filament\Forms\Components\DateTimePicker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmCallsRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmLunchesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmMeetingsRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmNotesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmTasksRelationManager;
use VentureDrake\LaravelCrmFilament\Resources\Leads\Pages\ViewLead;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * Issue #5. The inline activity forms default their timestamp to `now()`. With
 * seconds on, the native `datetime-local` input got a value like
 * `2026-11-17T03:59:16`. Where Filament left `step` empty, the browser applied
 * its 60 s default step and refused to submit the untouched form. The pickers
 * now work at minute precision, so the value never carries seconds, whatever
 * `step` the installed Filament version renders.
 */
beforeEach(function () {
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'Timestamp Precision Tester',
        'email' => 'timestamp-precision-' . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->assignRole('Owner');

    $this->actingAs($this->user->fresh());

    // Non-zero seconds: the exact value from the issue.
    Carbon::setTestNow('2026-11-17 03:59:16');

    $this->lead = Lead::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Timestamp precision lead',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function mountActivityRm(string $class, Lead $lead)
{
    return livewire($class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class]);
}

function activityPicker($component, string $field): DateTimePicker
{
    $picker = $component->instance()->form->getComponent(
        fn ($c) => $c instanceof DateTimePicker && $c->getName() === $field,
        withHidden: true,
    );

    expect($picker)->toBeInstanceOf(DateTimePicker::class);

    return $picker;
}

dataset('inline activity forms', [
    'notes' => [CrmNotesRelationManager::class, 'noted_at'],
    'calls' => [CrmCallsRelationManager::class, 'start_at'],
    'meetings' => [CrmMeetingsRelationManager::class, 'start_at'],
    'lunches' => [CrmLunchesRelationManager::class, 'start_at'],
    'tasks' => [CrmTasksRelationManager::class, 'due_at'],
]);

it('defaults the timestamp to the current minute, without seconds', function (string $class, string $field) {
    $component = mountActivityRm($class, $this->lead);

    expect($component->get("data.{$field}"))->toBe('2026-11-17 03:59')
        ->and($component->html())->not->toContain('03:59:16')
        ->and(activityPicker($component, $field)->hasSeconds())->toBeFalse();
})->with('inline activity forms');

it('renders the finish time at minute precision too', function (string $class) {
    $component = mountActivityRm($class, $this->lead);

    expect(activityPicker($component, 'finish_at')->hasSeconds())->toBeFalse();
})->with([
    'calls' => CrmCallsRelationManager::class,
    'meetings' => CrmMeetingsRelationManager::class,
    'lunches' => CrmLunchesRelationManager::class,
]);

it('drops the seconds from a stored note when it is edited', function () {
    $note = $this->lead->notes()->create([
        'external_id' => (string) Str::uuid(),
        'content' => 'Stored with seconds',
        'noted_at' => Carbon::parse('2026-11-17 03:59:16'),
    ]);

    mountActivityRm(CrmNotesRelationManager::class, $this->lead)
        ->call('editNote', $note->id)
        ->assertSet('data.noted_at', '2026-11-17 03:59');
});

it('saves an untouched default note timestamp at the minute', function () {
    mountActivityRm(CrmNotesRelationManager::class, $this->lead)
        ->set('data.content', 'Saved without touching the timestamp')
        ->call('createNote')
        ->assertHasNoErrors();

    $note = $this->lead->notes()->latest('id')->first();

    expect($note)->not->toBeNull()
        ->and($note->noted_at->format('Y-m-d H:i:s'))->toBe('2026-11-17 03:59:00');
});
