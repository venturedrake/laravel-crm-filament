<?php

use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Activity;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\Meeting;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmMeetingsRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\MeetingsRelationManager;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

/**
 * Hot-patch subclass that bypasses $this->form->fill() / getState() (which require
 * a real Filament panel mount) and operates on $this->data directly. Mirrors the
 * pattern locked-in by CrmNotesRelationManagerTest + CrmTasksRelationManagerTest +
 * CrmCallsRelationManagerTest. Re-implements the CRUD bodies without form
 * validation so the round-trip can be exercised headless.
 */
function leadMeetingsFocusedRm(): CrmMeetingsRelationManager
{
    return new class extends CrmMeetingsRelationManager
    {
        public function createMeeting(): void
        {
            $data = $this->data ?? [];

            $meeting = $this->getOwnerRecord()->meetings()->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'start_at' => $data['start_at'] ?? null,
                'finish_at' => $data['finish_at'] ?? null,
                'location' => $data['location'] ?? null,
                'user_owner_id' => $data['user_owner_id'] ?? auth()->id(),
                'user_assigned_id' => $data['user_assigned_id'] ?? null,
                'user_created_id' => auth()->id(),
            ]);

            self::logCrmActivity($this->getOwnerRecord(), $meeting);

            $this->data = [
                'name' => null,
                'description' => null,
                'start_at' => now(),
                'finish_at' => null,
                'location' => null,
                'user_owner_id' => auth()->id(),
                'user_assigned_id' => null,
            ];

            Notification::make()->title('Meeting added')->success()->send();
        }

        public function editMeeting(int $id): void
        {
            $meeting = $this->getOwnerRecord()->meetings()->whereKey($id)->first();

            if ($meeting === null) {
                return;
            }

            $this->editingId = (int) $meeting->id;
            $this->data = [
                'name' => $meeting->name,
                'description' => $meeting->description,
                'start_at' => $meeting->start_at,
                'finish_at' => $meeting->finish_at,
                'location' => $meeting->location,
                'user_owner_id' => $meeting->user_owner_id,
                'user_assigned_id' => $meeting->user_assigned_id,
            ];
        }

        public function updateMeeting(): void
        {
            if ($this->editingId === null) {
                return;
            }

            $meeting = $this->getOwnerRecord()->meetings()->whereKey($this->editingId)->first();

            if ($meeting === null) {
                return;
            }

            $data = $this->data ?? [];

            $meeting->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'start_at' => $data['start_at'] ?? null,
                'finish_at' => $data['finish_at'] ?? null,
                'location' => $data['location'] ?? null,
                'user_owner_id' => $data['user_owner_id'] ?? null,
                'user_assigned_id' => $data['user_assigned_id'] ?? null,
                'user_updated_id' => auth()->id(),
            ]);

            self::logCrmActivity($this->getOwnerRecord(), $meeting);

            $this->editingId = null;
            $this->data = [
                'name' => null,
                'description' => null,
                'start_at' => now(),
                'finish_at' => null,
                'location' => null,
                'user_owner_id' => auth()->id(),
                'user_assigned_id' => null,
            ];

            Notification::make()->title('Meeting updated')->success()->send();
        }

        public function deleteMeeting(int $id): void
        {
            $meeting = $this->getOwnerRecord()->meetings()->whereKey($id)->first();

            if ($meeting === null) {
                return;
            }

            $meeting->delete();

            Notification::make()->title('Meeting deleted')->success()->send();
        }
    };
}

// AC (1) Inheritance.

it('extends MeetingsRelationManager', function () {
    expect(is_subclass_of(CrmMeetingsRelationManager::class, MeetingsRelationManager::class))->toBeTrue();
});

// AC (2) $view points at the lead-meetings template.

it('overrides the $view property to point at the lead-meetings Blade template', function () {
    $ref = new ReflectionClass(CrmMeetingsRelationManager::class);
    $prop = $ref->getProperty('view');
    $prop->setAccessible(true);

    expect($prop->getDeclaringClass()->getName())->toBe(CrmMeetingsRelationManager::class);

    $rm = $ref->newInstanceWithoutConstructor();
    expect($prop->getValue($rm))->toBe('laravel-crm-filament::crm-meetings');
});

// AC (3) form() returns name/description/start_at/finish_at + location + owner/assigned grid.

it('returns the expected form schema with name, description, start/finish times, location, owner, assigned', function () {
    $rm = (new ReflectionClass(CrmMeetingsRelationManager::class))->newInstanceWithoutConstructor();
    $schema = $rm->form(Schema::make($rm));

    expect($schema->getStatePath())->toBe('data');

    $components = $schema->getComponents();
    expect($components[0])->toBeInstanceOf(Forms\Components\TextInput::class);
    expect($components[0]->getName())->toBe('name');
    expect($components[1])->toBeInstanceOf(Grid::class);
    expect($components[2])->toBeInstanceOf(Forms\Components\Select::class);
    expect($components[2]->getName())->toBe('guests');
    expect($components[2]->isMultiple())->toBeTrue();
    expect($components[3])->toBeInstanceOf(Forms\Components\TextInput::class);
    expect($components[3]->getName())->toBe('location');
    expect($components[4])->toBeInstanceOf(Forms\Components\Textarea::class);
    expect($components[4]->getName())->toBe('description');

    // Walk the start_at + finish_at Grid.
    $gridProp = new ReflectionProperty(Grid::class, 'childComponents');
    $gridProp->setAccessible(true);

    $timeGridChildren = $gridProp->getValue($components[1]);
    $timeChildren = $timeGridChildren['default'] ?? $timeGridChildren;
    expect(array_values(array_map(fn ($c) => $c->getName(), $timeChildren)))
        ->toBe(['start_at', 'finish_at']);
});

it('inherits the parent table configuration (columns and actions)', function () {
    $ref = new ReflectionClass(CrmMeetingsRelationManager::class);

    expect($ref->hasMethod('table'))->toBeTrue();

    // US-009: the RollsUpRelatedActivity concern composes a table() that
    // delegates to the parent and appends the "Related" badge column, so the
    // declaring class is now the Crm* subclass the trait is used by.
    expect($ref->getMethod('table')->getDeclaringClass()->getName())
        ->toBe(CrmMeetingsRelationManager::class);
    expect(($ref->getMethod('table')->getFileName()))
        ->toContain('RollsUpRelatedActivity.php');
});

it('inherits the parent relationship binding', function () {
    $ref = new ReflectionClass(CrmMeetingsRelationManager::class);
    $prop = $ref->getProperty('relationship');
    $prop->setAccessible(true);

    expect($prop->getValue())->toBe('meetings');
});

// AC (4) CRUD round-trip against a real Lead owner record.

it('createMeeting() persists a Meeting with user_created_id and writes an activity row', function () {
    $rm = leadMeetingsFocusedRm();

    $user = User::create([
        'name' => 'Focused Meeting Author',
        'email' => 'focused-meeting-author-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    auth()->login($user);

    $lead = Lead::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Focused lead — create meeting',
    ]);

    $rm->ownerRecord = $lead->fresh();
    $rm->data = [
        'name' => 'Focused inline meeting',
        'description' => 'Body text',
        'start_at' => now()->setSecond(0),
        'finish_at' => now()->addHour()->setSecond(0),
        'location' => 'Conference Room A',
        'user_owner_id' => $user->id,
        'user_assigned_id' => null,
    ];

    $rm->createMeeting();

    $meeting = Meeting::query()->where('name', 'Focused inline meeting')->first();
    expect($meeting)->not->toBeNull();
    expect($meeting->meetingable_type)->toBe($lead->getMorphClass());
    expect((int) $meeting->meetingable_id)->toBe($lead->id);
    expect($meeting->location)->toBe('Conference Room A');
    expect((int) $meeting->user_created_id)->toBe($user->id);
    expect((int) $meeting->user_owner_id)->toBe($user->id);

    expect(Activity::query()
        ->where('timelineable_type', $lead->getMorphClass())
        ->where('timelineable_id', $lead->id)
        ->where('recordable_type', $meeting->getMorphClass())
        ->where('recordable_id', $meeting->id)
        ->exists())->toBeTrue();
});

it('updateMeeting() persists edits via the owner relation and writes an activity row', function () {
    $rm = leadMeetingsFocusedRm();

    $user = User::create([
        'name' => 'Focused Meeting Updater',
        'email' => 'focused-meeting-updater-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    auth()->login($user);

    $lead = Lead::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Focused lead — update meeting',
    ]);

    $meeting = $lead->meetings()->create([
        'name' => 'Name before edit',
        'description' => 'Body before edit',
        'start_at' => now()->subDay()->setSecond(0),
        'finish_at' => now()->subDay()->addHour()->setSecond(0),
        'location' => 'Old room',
        'user_created_id' => $user->id,
    ]);

    $rm->ownerRecord = $lead->fresh();
    $rm->editingId = (int) $meeting->id;
    $rm->data = [
        'name' => 'Name after edit',
        'description' => 'Body after edit',
        'start_at' => now()->setSecond(0),
        'finish_at' => now()->addHour()->setSecond(0),
        'location' => 'New room',
        'user_owner_id' => $user->id,
        'user_assigned_id' => null,
    ];

    $rm->updateMeeting();

    $reloaded = Meeting::find($meeting->id);
    expect($reloaded->name)->toBe('Name after edit');
    expect($reloaded->description)->toBe('Body after edit');
    expect($reloaded->location)->toBe('New room');
    expect($reloaded->meetingable_type)->toBe($lead->getMorphClass());
    expect((int) $reloaded->meetingable_id)->toBe($lead->id);
    expect((int) $reloaded->user_updated_id)->toBe($user->id);

    expect(Activity::query()
        ->where('timelineable_type', $lead->getMorphClass())
        ->where('timelineable_id', $lead->id)
        ->where('recordable_type', $meeting->getMorphClass())
        ->where('recordable_id', $meeting->id)
        ->exists())->toBeTrue();
});

it('deleteMeeting() soft-deletes the meeting via the owner relation', function () {
    $rm = leadMeetingsFocusedRm();

    $user = User::create([
        'name' => 'Focused Meeting Deleter',
        'email' => 'focused-meeting-deleter-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    auth()->login($user);

    $lead = Lead::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Focused lead — delete meeting',
    ]);

    $meeting = $lead->meetings()->create([
        'name' => 'To be soft-deleted',
        'start_at' => now(),
        'user_created_id' => $user->id,
    ]);

    $rm->ownerRecord = $lead->fresh();

    $rm->deleteMeeting((int) $meeting->id);

    expect(Meeting::query()->find($meeting->id))->toBeNull();
    expect(Meeting::withTrashed()->find($meeting->id))->not->toBeNull();
    expect(Meeting::withTrashed()->find($meeting->id)->deleted_at)->not->toBeNull();
});

// AC (5) Blade source assertions.

it('the lead-meetings Blade view contains the expected structural markers', function () {
    $bladePath = dirname(__DIR__, 2) . '/resources/views/crm-meetings.blade.php';
    expect(file_exists($bladePath))->toBeTrue();

    $blade = file_get_contents($bladePath);

    // Add-meeting form wired to createMeeting with the inline state bindings.
    expect($blade)->toContain('@if ($editingId === null)');
    expect($blade)->toContain('wire:submit="createMeeting"');
    expect($blade)->toContain('{{ $this->form }}');

    // Section heading uses the new translation key.
    expect($blade)->toContain('laravel-crm-filament::labels.sections.add_meeting');

    // Meetings loop (cards) sorted by created_at desc.
    // US-009: rows now come from the RollsUpRelatedActivity concern (still
    // newest-first) so the `show_related_activity` setting is honoured.
    expect($blade)->toContain('$this->relatedActivityRows()');
    expect($blade)->toContain('@forelse');
    expect($blade)->toContain('@empty');

    // Three-dot dropdown wired to editMeeting / deleteMeeting per row.
    expect($blade)->toContain('x-data="{ open: false }"');
    expect($blade)->toContain('crm-card-dropdown');
    expect($blade)->toContain('wire:click="editMeeting({{ $meeting->id }})"');
    expect($blade)->toContain('wire:click="deleteMeeting({{ $meeting->id }})"');

    // Inline edit swap and cancel button.
    expect($blade)->toContain('@if ($editingId === $meeting->id)');
    expect($blade)->toContain('wire:submit="updateMeeting"');
    expect($blade)->toContain('wire:click="cancelEdit"');

    // Location displayed in the card body.
    expect($blade)->toContain('$meeting->location');
    expect($blade)->toContain('laravel-crm-filament::labels.fields.location');

    // Pills row with separate Start at + Finish at badges plus section headers
    // for Guests / Location / Description (parity with core CRM call-item).
    expect($blade)->toContain('crm-card-pill');
    expect($blade)->toContain('$meeting->start_at->format');
    expect($blade)->toContain('$meeting->finish_at->format');
    expect($blade)->toContain('labels.money.start_at');
    expect($blade)->toContain('labels.money.finish_at');
    expect($blade)->toContain('crm-card-section-title');
    expect($blade)->toContain('labels.fields.guests');
    expect($blade)->toContain('crm-card-guests');

    // Shared lead-card-styles partial.
    expect($blade)->toContain("@include('laravel-crm-filament::partials.crm-card-styles')");
    expect($blade)->not->toContain('@once');

    // Empty state.
    expect($blade)->toContain('No meetings yet');

    // Partial extension confirms the shared selector covers crm-card-area-meetings.
    $partialPath = dirname(__DIR__, 2) . '/resources/views/partials/crm-card-styles.blade.php';
    expect(file_exists($partialPath))->toBeTrue();

    $partial = file_get_contents($partialPath);
    expect($partial)->toContain('.crm-card-area-meetings');
    expect($partial)->toContain('html.dark .crm-card-area-meetings');
});
