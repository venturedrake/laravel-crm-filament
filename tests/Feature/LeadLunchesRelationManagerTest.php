<?php

use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Activity;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\Lunch;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmLunchesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\LunchesRelationManager;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

/**
 * Hot-patch subclass that bypasses $this->form->fill() / getState() (which require
 * a real Filament panel mount) and operates on $this->data directly. Mirrors the
 * pattern locked-in by CrmNotesRelationManagerTest + CrmTasksRelationManagerTest +
 * CrmCallsRelationManagerTest + CrmMeetingsRelationManagerTest. Re-implements
 * the CRUD bodies without form validation so the round-trip can be exercised
 * headless.
 */
function leadLunchesFocusedRm(): CrmLunchesRelationManager
{
    return new class extends CrmLunchesRelationManager
    {
        public function createLunch(): void
        {
            $data = $this->data ?? [];

            $lunch = $this->getOwnerRecord()->lunches()->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'start_at' => $data['start_at'] ?? null,
                'finish_at' => $data['finish_at'] ?? null,
                'location' => $data['location'] ?? null,
                'user_owner_id' => $data['user_owner_id'] ?? auth()->id(),
                'user_assigned_id' => $data['user_assigned_id'] ?? null,
                'user_created_id' => auth()->id(),
            ]);

            self::logCrmActivity($this->getOwnerRecord(), $lunch);

            $this->data = [
                'name' => null,
                'description' => null,
                'start_at' => now(),
                'finish_at' => null,
                'location' => null,
                'user_owner_id' => auth()->id(),
                'user_assigned_id' => null,
            ];

            Notification::make()->title('Lunch added')->success()->send();
        }

        public function editLunch(int $id): void
        {
            $lunch = $this->getOwnerRecord()->lunches()->whereKey($id)->first();

            if ($lunch === null) {
                return;
            }

            $this->editingId = (int) $lunch->id;
            $this->data = [
                'name' => $lunch->name,
                'description' => $lunch->description,
                'start_at' => $lunch->start_at,
                'finish_at' => $lunch->finish_at,
                'location' => $lunch->location,
                'user_owner_id' => $lunch->user_owner_id,
                'user_assigned_id' => $lunch->user_assigned_id,
            ];
        }

        public function updateLunch(): void
        {
            if ($this->editingId === null) {
                return;
            }

            $lunch = $this->getOwnerRecord()->lunches()->whereKey($this->editingId)->first();

            if ($lunch === null) {
                return;
            }

            $data = $this->data ?? [];

            $lunch->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'start_at' => $data['start_at'] ?? null,
                'finish_at' => $data['finish_at'] ?? null,
                'location' => $data['location'] ?? null,
                'user_owner_id' => $data['user_owner_id'] ?? null,
                'user_assigned_id' => $data['user_assigned_id'] ?? null,
                'user_updated_id' => auth()->id(),
            ]);

            self::logCrmActivity($this->getOwnerRecord(), $lunch);

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

            Notification::make()->title('Lunch updated')->success()->send();
        }

        public function deleteLunch(int $id): void
        {
            $lunch = $this->getOwnerRecord()->lunches()->whereKey($id)->first();

            if ($lunch === null) {
                return;
            }

            $lunch->delete();

            Notification::make()->title('Lunch deleted')->success()->send();
        }
    };
}

// AC (1) Inheritance from LunchesRelationManager + isReadOnly() flipped to false.

it('extends LunchesRelationManager', function () {
    expect(is_subclass_of(CrmLunchesRelationManager::class, LunchesRelationManager::class))->toBeTrue();
});

it('overrides isReadOnly() to return false (flipping the parent read-only contract)', function () {
    $rm = (new ReflectionClass(CrmLunchesRelationManager::class))->newInstanceWithoutConstructor();
    expect($rm->isReadOnly())->toBeFalse();

    // Standalone LunchesRelationManager remains read-only (parent untouched).
    $parent = (new ReflectionClass(LunchesRelationManager::class))->newInstanceWithoutConstructor();
    expect($parent->isReadOnly())->toBeTrue();
});

// AC (2) $view points at the lead-lunches template.

it('overrides the $view property to point at the lead-lunches Blade template', function () {
    $ref = new ReflectionClass(CrmLunchesRelationManager::class);
    $prop = $ref->getProperty('view');
    $prop->setAccessible(true);

    expect($prop->getDeclaringClass()->getName())->toBe(CrmLunchesRelationManager::class);

    $rm = $ref->newInstanceWithoutConstructor();
    expect($prop->getValue($rm))->toBe('laravel-crm-filament::crm-lunches');
});

// AC (3) form() returns name/description/start_at/finish_at + location + owner/assigned grid.

it('returns the expected form schema with name, description, start/finish times, location, owner, assigned', function () {
    $rm = (new ReflectionClass(CrmLunchesRelationManager::class))->newInstanceWithoutConstructor();
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

    // Walk the first Grid (start_at + finish_at).
    $gridProp = new ReflectionProperty(Grid::class, 'childComponents');
    $gridProp->setAccessible(true);

    $timeGridChildren = $gridProp->getValue($components[1]);
    $timeChildren = $timeGridChildren['default'] ?? $timeGridChildren;
    expect(array_values(array_map(fn ($c) => $c->getName(), $timeChildren)))
        ->toBe(['start_at', 'finish_at']);
});

it('inherits the parent table configuration (columns and actions)', function () {
    $ref = new ReflectionClass(CrmLunchesRelationManager::class);

    expect($ref->hasMethod('table'))->toBeTrue();

    // US-009: the RollsUpRelatedActivity concern composes a table() that
    // delegates to the parent and appends the "Related" badge column, so the
    // declaring class is now the Crm* subclass the trait is used by.
    expect($ref->getMethod('table')->getDeclaringClass()->getName())
        ->toBe(CrmLunchesRelationManager::class);
    expect(($ref->getMethod('table')->getFileName()))
        ->toContain('RollsUpRelatedActivity.php');
});

it('inherits the parent relationship binding', function () {
    $ref = new ReflectionClass(CrmLunchesRelationManager::class);
    $prop = $ref->getProperty('relationship');
    $prop->setAccessible(true);

    expect($prop->getValue())->toBe('lunches');
});

// AC (4) CRUD round-trip against a real Lead owner record.

it('createLunch() persists a Lunch with user_created_id and writes an activity row', function () {
    $rm = leadLunchesFocusedRm();

    $user = User::create([
        'name' => 'Focused Lunch Author',
        'email' => 'focused-lunch-author-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    auth()->login($user);

    $lead = Lead::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Focused lead — create lunch',
    ]);

    $rm->ownerRecord = $lead->fresh();
    $rm->data = [
        'name' => 'Focused inline lunch',
        'description' => 'Body text',
        'start_at' => now()->setSecond(0),
        'finish_at' => now()->addHour()->setSecond(0),
        'location' => 'Cafe on the corner',
        'user_owner_id' => $user->id,
        'user_assigned_id' => null,
    ];

    $rm->createLunch();

    $lunch = Lunch::query()->where('name', 'Focused inline lunch')->first();
    expect($lunch)->not->toBeNull();
    expect($lunch->lunchable_type)->toBe($lead->getMorphClass());
    expect((int) $lunch->lunchable_id)->toBe($lead->id);
    expect($lunch->location)->toBe('Cafe on the corner');
    expect((int) $lunch->user_created_id)->toBe($user->id);
    expect((int) $lunch->user_owner_id)->toBe($user->id);

    expect(Activity::query()
        ->where('timelineable_type', $lead->getMorphClass())
        ->where('timelineable_id', $lead->id)
        ->where('recordable_type', $lunch->getMorphClass())
        ->where('recordable_id', $lunch->id)
        ->exists())->toBeTrue();
});

it('updateLunch() persists edits via the owner relation and writes an activity row', function () {
    $rm = leadLunchesFocusedRm();

    $user = User::create([
        'name' => 'Focused Lunch Updater',
        'email' => 'focused-lunch-updater-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    auth()->login($user);

    $lead = Lead::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Focused lead — update lunch',
    ]);

    $lunch = $lead->lunches()->create([
        'name' => 'Name before edit',
        'description' => 'Body before edit',
        'start_at' => now()->subDay()->setSecond(0),
        'finish_at' => now()->subDay()->addHour()->setSecond(0),
        'location' => 'Old place',
        'user_created_id' => $user->id,
    ]);

    $rm->ownerRecord = $lead->fresh();
    $rm->editingId = (int) $lunch->id;
    $rm->data = [
        'name' => 'Name after edit',
        'description' => 'Body after edit',
        'start_at' => now()->setSecond(0),
        'finish_at' => now()->addHour()->setSecond(0),
        'location' => 'New place',
        'user_owner_id' => $user->id,
        'user_assigned_id' => null,
    ];

    $rm->updateLunch();

    $reloaded = Lunch::find($lunch->id);
    expect($reloaded->name)->toBe('Name after edit');
    expect($reloaded->description)->toBe('Body after edit');
    expect($reloaded->location)->toBe('New place');
    expect($reloaded->lunchable_type)->toBe($lead->getMorphClass());
    expect((int) $reloaded->lunchable_id)->toBe($lead->id);
    expect((int) $reloaded->user_updated_id)->toBe($user->id);

    expect(Activity::query()
        ->where('timelineable_type', $lead->getMorphClass())
        ->where('timelineable_id', $lead->id)
        ->where('recordable_type', $lunch->getMorphClass())
        ->where('recordable_id', $lunch->id)
        ->exists())->toBeTrue();
});

it('deleteLunch() soft-deletes the lunch via the owner relation', function () {
    $rm = leadLunchesFocusedRm();

    $user = User::create([
        'name' => 'Focused Lunch Deleter',
        'email' => 'focused-lunch-deleter-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    auth()->login($user);

    $lead = Lead::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Focused lead — delete lunch',
    ]);

    $lunch = $lead->lunches()->create([
        'name' => 'To be soft-deleted',
        'start_at' => now(),
        'user_created_id' => $user->id,
    ]);

    $rm->ownerRecord = $lead->fresh();

    $rm->deleteLunch((int) $lunch->id);

    expect(Lunch::query()->find($lunch->id))->toBeNull();
    expect(Lunch::withTrashed()->find($lunch->id))->not->toBeNull();
    expect(Lunch::withTrashed()->find($lunch->id)->deleted_at)->not->toBeNull();
});

// AC (5) Blade source assertions.

it('the lead-lunches Blade view contains the expected structural markers', function () {
    $bladePath = dirname(__DIR__, 2) . '/resources/views/crm-lunches.blade.php';
    expect(file_exists($bladePath))->toBeTrue();

    $blade = file_get_contents($bladePath);

    // Add-lunch form wired to createLunch with the inline state bindings.
    expect($blade)->toContain('@if ($editingId === null)');
    expect($blade)->toContain('wire:submit="createLunch"');
    expect($blade)->toContain('{{ $this->form }}');

    // Section heading uses the new translation key.
    expect($blade)->toContain('laravel-crm-filament::labels.sections.add_lunch');

    // Lunches loop (cards) sorted by created_at desc.
    // US-009: rows now come from the RollsUpRelatedActivity concern (still
    // newest-first) so the `show_related_activity` setting is honoured.
    expect($blade)->toContain('$this->relatedActivityRows()');
    expect($blade)->toContain('@forelse');
    expect($blade)->toContain('@empty');

    // Three-dot dropdown wired to editLunch / deleteLunch per row.
    expect($blade)->toContain('x-data="{ open: false }"');
    expect($blade)->toContain('crm-card-dropdown');
    expect($blade)->toContain('wire:click="editLunch({{ $lunch->id }})"');
    expect($blade)->toContain('wire:click="deleteLunch({{ $lunch->id }})"');

    // Inline edit swap and cancel button.
    expect($blade)->toContain('@if ($editingId === $lunch->id)');
    expect($blade)->toContain('wire:submit="updateLunch"');
    expect($blade)->toContain('wire:click="cancelEdit"');

    // Location displayed in the card body.
    expect($blade)->toContain('$lunch->location');
    expect($blade)->toContain('laravel-crm-filament::labels.fields.location');

    // Pills row with separate Start at + Finish at badges plus section headers
    // for Guests / Location / Description (parity with core CRM call-item).
    expect($blade)->toContain('crm-card-pill');
    expect($blade)->toContain('$lunch->start_at->format');
    expect($blade)->toContain('$lunch->finish_at->format');
    expect($blade)->toContain('labels.money.start_at');
    expect($blade)->toContain('labels.money.finish_at');
    expect($blade)->toContain('crm-card-section-title');
    expect($blade)->toContain('labels.fields.guests');
    expect($blade)->toContain('crm-card-guests');

    // Shared lead-card-styles partial.
    expect($blade)->toContain("@include('laravel-crm-filament::partials.crm-card-styles')");
    expect($blade)->not->toContain('@once');

    // Empty state.
    expect($blade)->toContain('No lunches yet');

    // Partial extension confirms the shared selector covers crm-card-area-lunches.
    $partialPath = dirname(__DIR__, 2) . '/resources/views/partials/crm-card-styles.blade.php';
    expect(file_exists($partialPath))->toBeTrue();

    $partial = file_get_contents($partialPath);
    expect($partial)->toContain('.crm-card-area-lunches');
    expect($partial)->toContain('html.dark .crm-card-area-lunches');
});
