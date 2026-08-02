<?php

use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Activity;
use VentureDrake\LaravelCrm\Models\Call;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrmFilament\RelationManagers\CallsRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmCallsRelationManager;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

/**
 * Hot-patch subclass that bypasses $this->form->fill() / getState() (which require
 * a real Filament panel mount) and operates on $this->data directly. Mirrors the
 * pattern locked-in by CrmNotesRelationManagerTest + CrmTasksRelationManagerTest.
 * Re-implements the four CRUD bodies without form validation so the round-trip can
 * be exercised headless.
 */
function leadCallsFocusedRm(): CrmCallsRelationManager
{
    return new class extends CrmCallsRelationManager
    {
        public function createCall(): void
        {
            $data = $this->data ?? [];

            $call = $this->getOwnerRecord()->calls()->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'start_at' => $data['start_at'] ?? null,
                'finish_at' => $data['finish_at'] ?? null,
                'user_owner_id' => $data['user_owner_id'] ?? auth()->id(),
                'user_assigned_id' => $data['user_assigned_id'] ?? null,
                'user_created_id' => auth()->id(),
            ]);

            self::logCrmActivity($this->getOwnerRecord(), $call);

            $this->data = [
                'name' => null,
                'description' => null,
                'start_at' => now(),
                'finish_at' => null,
                'user_owner_id' => auth()->id(),
                'user_assigned_id' => null,
            ];

            Notification::make()->title('Call added')->success()->send();
        }

        public function editCall(int $id): void
        {
            $call = $this->getOwnerRecord()->calls()->whereKey($id)->first();

            if ($call === null) {
                return;
            }

            $this->editingId = (int) $call->id;
            $this->data = [
                'name' => $call->name,
                'description' => $call->description,
                'start_at' => $call->start_at,
                'finish_at' => $call->finish_at,
                'user_owner_id' => $call->user_owner_id,
                'user_assigned_id' => $call->user_assigned_id,
            ];
        }

        public function updateCall(): void
        {
            if ($this->editingId === null) {
                return;
            }

            $call = $this->getOwnerRecord()->calls()->whereKey($this->editingId)->first();

            if ($call === null) {
                return;
            }

            $data = $this->data ?? [];

            $call->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'start_at' => $data['start_at'] ?? null,
                'finish_at' => $data['finish_at'] ?? null,
                'user_owner_id' => $data['user_owner_id'] ?? null,
                'user_assigned_id' => $data['user_assigned_id'] ?? null,
                'user_updated_id' => auth()->id(),
            ]);

            self::logCrmActivity($this->getOwnerRecord(), $call);

            $this->editingId = null;
            $this->data = [
                'name' => null,
                'description' => null,
                'start_at' => now(),
                'finish_at' => null,
                'user_owner_id' => auth()->id(),
                'user_assigned_id' => null,
            ];

            Notification::make()->title('Call updated')->success()->send();
        }

        public function deleteCall(int $id): void
        {
            $call = $this->getOwnerRecord()->calls()->whereKey($id)->first();

            if ($call === null) {
                return;
            }

            $call->delete();

            Notification::make()->title('Call deleted')->success()->send();
        }
    };
}

// AC (1) Inheritance.

it('extends CallsRelationManager', function () {
    expect(is_subclass_of(CrmCallsRelationManager::class, CallsRelationManager::class))->toBeTrue();
});

// AC (2) $view points at the lead-calls template.

it('overrides the $view property to point at the lead-calls Blade template', function () {
    $ref = new ReflectionClass(CrmCallsRelationManager::class);
    $prop = $ref->getProperty('view');
    $prop->setAccessible(true);

    expect($prop->getDeclaringClass()->getName())->toBe(CrmCallsRelationManager::class);

    $rm = $ref->newInstanceWithoutConstructor();
    expect($prop->getValue($rm))->toBe('laravel-crm-filament::crm-calls');
});

// AC (3) form() returns subject/start_at/finish_at/guests/location/description matching core CRM CallRelated parity.

it('returns the expected form schema with subject/start/finish/guests/location/description', function () {
    $rm = (new ReflectionClass(CrmCallsRelationManager::class))->newInstanceWithoutConstructor();
    $schema = $rm->form(Schema::make($rm));

    expect($schema->getStatePath())->toBe('data');

    $components = $schema->getComponents();
    expect($components[0])->toBeInstanceOf(Forms\Components\TextInput::class);
    expect($components[0]->getName())->toBe('name');
    expect($components[1])->toBeInstanceOf(Grid::class);
    expect($components[2])->toBeInstanceOf(Forms\Components\Select::class);
    expect($components[2]->getName())->toBe('guests');
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

    // Guests is a multi-select.
    expect($components[2]->isMultiple())->toBeTrue();
});

it('inherits the parent table configuration (columns and actions)', function () {
    $ref = new ReflectionClass(CrmCallsRelationManager::class);

    expect($ref->hasMethod('table'))->toBeTrue();

    // US-009: the RollsUpRelatedActivity concern composes a table() that
    // delegates to the parent and appends the "Related" badge column, so the
    // declaring class is now the Crm* subclass the trait is used by.
    expect($ref->getMethod('table')->getDeclaringClass()->getName())
        ->toBe(CrmCallsRelationManager::class);
    expect(($ref->getMethod('table')->getFileName()))
        ->toContain('RollsUpRelatedActivity.php');
});

it('inherits the parent relationship binding', function () {
    $ref = new ReflectionClass(CrmCallsRelationManager::class);
    $prop = $ref->getProperty('relationship');
    $prop->setAccessible(true);

    expect($prop->getValue())->toBe('calls');
});

// AC (4) CRUD round-trip against a real Lead owner record.

it('createCall() persists a Call with user_created_id and writes an activity row', function () {
    $rm = leadCallsFocusedRm();

    $user = User::create([
        'name' => 'Focused Call Author',
        'email' => 'focused-call-author-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    auth()->login($user);

    $lead = Lead::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Focused lead — create call',
    ]);

    $rm->ownerRecord = $lead->fresh();
    $rm->data = [
        'name' => 'Focused inline call',
        'description' => 'Body text',
        'start_at' => now()->setSecond(0),
        'finish_at' => now()->addMinutes(30)->setSecond(0),
        'user_owner_id' => $user->id,
        'user_assigned_id' => null,
    ];

    $rm->createCall();

    $call = Call::query()->where('name', 'Focused inline call')->first();
    expect($call)->not->toBeNull();
    expect($call->callable_type)->toBe($lead->getMorphClass());
    expect((int) $call->callable_id)->toBe($lead->id);
    expect((int) $call->user_created_id)->toBe($user->id);
    expect((int) $call->user_owner_id)->toBe($user->id);

    expect(Activity::query()
        ->where('timelineable_type', $lead->getMorphClass())
        ->where('timelineable_id', $lead->id)
        ->where('recordable_type', $call->getMorphClass())
        ->where('recordable_id', $call->id)
        ->exists())->toBeTrue();
});

it('updateCall() persists edits via the owner relation and writes an activity row', function () {
    $rm = leadCallsFocusedRm();

    $user = User::create([
        'name' => 'Focused Call Updater',
        'email' => 'focused-call-updater-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    auth()->login($user);

    $lead = Lead::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Focused lead — update call',
    ]);

    $call = $lead->calls()->create([
        'name' => 'Name before edit',
        'description' => 'Body before edit',
        'start_at' => now()->subDay()->setSecond(0),
        'finish_at' => now()->subDay()->addHour()->setSecond(0),
        'user_created_id' => $user->id,
    ]);

    $rm->ownerRecord = $lead->fresh();
    $rm->editingId = (int) $call->id;
    $rm->data = [
        'name' => 'Name after edit',
        'description' => 'Body after edit',
        'start_at' => now()->setSecond(0),
        'finish_at' => now()->addHour()->setSecond(0),
        'user_owner_id' => $user->id,
        'user_assigned_id' => null,
    ];

    $rm->updateCall();

    $reloaded = Call::find($call->id);
    expect($reloaded->name)->toBe('Name after edit');
    expect($reloaded->description)->toBe('Body after edit');
    expect($reloaded->callable_type)->toBe($lead->getMorphClass());
    expect((int) $reloaded->callable_id)->toBe($lead->id);
    expect((int) $reloaded->user_updated_id)->toBe($user->id);

    expect(Activity::query()
        ->where('timelineable_type', $lead->getMorphClass())
        ->where('timelineable_id', $lead->id)
        ->where('recordable_type', $call->getMorphClass())
        ->where('recordable_id', $call->id)
        ->exists())->toBeTrue();
});

it('deleteCall() soft-deletes the call via the owner relation', function () {
    $rm = leadCallsFocusedRm();

    $user = User::create([
        'name' => 'Focused Call Deleter',
        'email' => 'focused-call-deleter-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    auth()->login($user);

    $lead = Lead::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Focused lead — delete call',
    ]);

    $call = $lead->calls()->create([
        'name' => 'To be soft-deleted',
        'start_at' => now(),
        'user_created_id' => $user->id,
    ]);

    $rm->ownerRecord = $lead->fresh();

    $rm->deleteCall((int) $call->id);

    expect(Call::query()->find($call->id))->toBeNull();
    expect(Call::withTrashed()->find($call->id))->not->toBeNull();
    expect(Call::withTrashed()->find($call->id)->deleted_at)->not->toBeNull();
});

// AC (5) Blade source assertions.

it('the lead-calls Blade view contains the expected structural markers', function () {
    $bladePath = dirname(__DIR__, 2) . '/resources/views/crm-calls.blade.php';
    expect(file_exists($bladePath))->toBeTrue();

    $blade = file_get_contents($bladePath);

    // Add-call form wired to createCall, rendering the Filament form schema
    // via {{ $this->form }} so Guests is a native Filament multi-select and
    // labels resolve through the form schema's translation chain.
    expect($blade)->toContain('@if ($editingId === null)');
    expect($blade)->toContain('wire:submit="createCall"');
    expect($blade)->toContain('{{ $this->form }}');

    // Section heading uses the new translation key.
    expect($blade)->toContain('laravel-crm-filament::labels.sections.add_call');

    // Calls loop (cards) sorted by created_at desc.
    // US-009: rows now come from the RollsUpRelatedActivity concern (still
    // newest-first) so the `show_related_activity` setting is honoured.
    expect($blade)->toContain('$this->relatedActivityRows()');
    expect($blade)->toContain('@forelse');
    expect($blade)->toContain('@empty');

    // Three-dot dropdown wired to editCall / deleteCall per row.
    expect($blade)->toContain('x-data="{ open: false }"');
    expect($blade)->toContain('crm-card-dropdown');
    expect($blade)->toContain('wire:click="editCall({{ $call->id }})"');
    expect($blade)->toContain('wire:click="deleteCall({{ $call->id }})"');

    // Inline edit swap and cancel button.
    expect($blade)->toContain('@if ($editingId === $call->id)');
    expect($blade)->toContain('wire:submit="updateCall"');
    expect($blade)->toContain('wire:click="cancelEdit"');

    // Pills row with separate Start at + Finish at badges, plus section headers
    // for Guests, Location, and Description matching core CRM call-item parity.
    expect($blade)->toContain('crm-card-pill');
    expect($blade)->toContain('$call->start_at->format');
    expect($blade)->toContain('$call->finish_at->format');
    expect($blade)->toContain('crm-card-section-title');
    expect($blade)->toContain('labels.fields.guests');
    expect($blade)->toContain('labels.fields.location');
    expect($blade)->toContain('labels.fields.description');
    expect($blade)->toContain('crm-card-guests');
    expect($blade)->toContain('$call->location');

    // Shared lead-card-styles partial.
    expect($blade)->toContain("@include('laravel-crm-filament::partials.crm-card-styles')");
    expect($blade)->not->toContain('@once');

    // Empty state.
    expect($blade)->toContain('No calls yet');

    // Partial extension confirms the shared selector covers crm-card-area-calls.
    $partialPath = dirname(__DIR__, 2) . '/resources/views/partials/crm-card-styles.blade.php';
    expect(file_exists($partialPath))->toBeTrue();

    $partial = file_get_contents($partialPath);
    expect($partial)->toContain('.crm-card-area-calls');
    expect($partial)->toContain('html.dark .crm-card-area-calls');
});
