<?php

use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Activity;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\Note;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmNotesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\NotesRelationManager;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

/**
 * Hot-patch subclass that bypasses $this->form->fill() / getState() (which require
 * a real Filament panel mount) and operates on $this->data directly. Mirrors the
 * pattern locked-in by LeadNotesCreateNoteTest / LeadNotesEditLifecycleTest /
 * LeadNotesDeleteNoteTest. Re-implements the four CRUD bodies without form
 * validation so the round-trip can be exercised headless.
 */
function leadNotesFocusedRm(): CrmNotesRelationManager
{
    return new class extends CrmNotesRelationManager
    {
        public function createNote(): void
        {
            $data = $this->data ?? [];

            $note = $this->getOwnerRecord()->notes()->create([
                'content' => $data['content'],
                'noted_at' => $data['noted_at'] ?? null,
                'user_created_id' => auth()->id(),
            ]);

            self::logCrmActivity($this->getOwnerRecord(), $note);

            $this->data = ['content' => null, 'noted_at' => now()];

            Notification::make()->title('Note added')->success()->send();
        }

        public function editNote(int $id): void
        {
            $note = $this->getOwnerRecord()->notes()->whereKey($id)->first();

            if ($note === null) {
                return;
            }

            $this->editingId = (int) $note->id;
            $this->data = [
                'content' => $note->content,
                'noted_at' => $note->noted_at,
            ];
        }

        public function updateNote(): void
        {
            if ($this->editingId === null) {
                return;
            }

            $note = $this->getOwnerRecord()->notes()->whereKey($this->editingId)->first();

            if ($note === null) {
                return;
            }

            $data = $this->data ?? [];

            $note->update([
                'content' => $data['content'],
                'noted_at' => $data['noted_at'] ?? null,
                'user_updated_id' => auth()->id(),
            ]);

            self::logCrmActivity($this->getOwnerRecord(), $note);

            $this->editingId = null;
            $this->data = ['content' => null, 'noted_at' => now()];

            Notification::make()->title('Note updated')->success()->send();
        }

        public function deleteNote(int $id): void
        {
            $note = $this->getOwnerRecord()->notes()->whereKey($id)->first();

            if ($note === null) {
                return;
            }

            $note->delete();

            Notification::make()->title('Note deleted')->success()->send();
        }
    };
}

// AC (1) Inheritance.

it('extends NotesRelationManager', function () {
    expect(is_subclass_of(CrmNotesRelationManager::class, NotesRelationManager::class))->toBeTrue();
});

// AC (2) $view points at the lead-notes template.

it('overrides the $view property to point at the lead-notes Blade template', function () {
    $ref = new ReflectionClass(CrmNotesRelationManager::class);
    $prop = $ref->getProperty('view');
    $prop->setAccessible(true);

    expect($prop->getDeclaringClass()->getName())->toBe(CrmNotesRelationManager::class);

    $rm = $ref->newInstanceWithoutConstructor();
    expect($prop->getValue($rm))->toBe('laravel-crm-filament::crm-notes');
});

// AC (3) form() returns only Note + Noted-at, no pinned.

it('returns a 2-field form schema with Note + Noted-at and no pinned field', function () {
    $rm = (new ReflectionClass(CrmNotesRelationManager::class))->newInstanceWithoutConstructor();
    $schema = $rm->form(Schema::make($rm));

    $components = $schema->getComponents();
    $names = array_values(array_map(fn ($c) => $c->getName(), $components));

    expect($names)->toBe(['content', 'noted_at']);
    expect($components[0])->toBeInstanceOf(Forms\Components\Textarea::class);
    expect($components[1])->toBeInstanceOf(Forms\Components\DateTimePicker::class);

    expect($names)->not->toContain('pinned');
});

it('inherits the parent table configuration (columns and actions)', function () {
    $ref = new ReflectionClass(CrmNotesRelationManager::class);

    expect($ref->hasMethod('table'))->toBeTrue();

    // US-009: the RollsUpRelatedActivity concern composes a table() that
    // delegates to the parent and appends the "Related" badge column, so the
    // declaring class is now the Crm* subclass the trait is used by.
    expect($ref->getMethod('table')->getDeclaringClass()->getName())
        ->toBe(CrmNotesRelationManager::class);
    expect(($ref->getMethod('table')->getFileName()))
        ->toContain('RollsUpRelatedActivity.php');
});

it('inherits the parent relationship binding', function () {
    $ref = new ReflectionClass(CrmNotesRelationManager::class);
    $prop = $ref->getProperty('relationship');
    $prop->setAccessible(true);

    expect($prop->getValue())->toBe('notes');
});

// AC (4) CRUD round-trip against a real Lead owner record.

it('createNote() persists a Note with user_created_id and writes an activity row', function () {
    $rm = leadNotesFocusedRm();

    $user = User::create([
        'name' => 'Focused Note Author',
        'email' => 'focused-author-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    auth()->login($user);

    $lead = Lead::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Focused lead — create',
    ]);

    $rm->ownerRecord = $lead->fresh();
    $rm->data = [
        'content' => 'Focused inline note',
        'noted_at' => now()->setSecond(0),
    ];

    $rm->createNote();

    $note = Note::query()->where('content', 'Focused inline note')->first();
    expect($note)->not->toBeNull();
    expect($note->noteable_type)->toBe($lead->getMorphClass());
    expect((int) $note->noteable_id)->toBe($lead->id);
    expect((int) $note->user_created_id)->toBe($user->id);

    expect(Activity::query()
        ->where('timelineable_type', $lead->getMorphClass())
        ->where('timelineable_id', $lead->id)
        ->where('recordable_type', $note->getMorphClass())
        ->where('recordable_id', $note->id)
        ->exists())->toBeTrue();
});

it('updateNote() persists edits via the owner relation and writes an activity row', function () {
    $rm = leadNotesFocusedRm();

    $user = User::create([
        'name' => 'Focused Note Updater',
        'email' => 'focused-updater-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    auth()->login($user);

    $lead = Lead::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Focused lead — update',
    ]);

    $note = $lead->notes()->create([
        'content' => 'Body before edit',
        'noted_at' => now()->subDay()->setSecond(0),
        'user_created_id' => $user->id,
    ]);

    $rm->ownerRecord = $lead->fresh();
    $rm->editingId = (int) $note->id;
    $rm->data = [
        'content' => 'Body after edit',
        'noted_at' => now()->setSecond(0),
    ];

    $rm->updateNote();

    $reloaded = Note::find($note->id);
    expect($reloaded->content)->toBe('Body after edit');
    expect($reloaded->noteable_type)->toBe($lead->getMorphClass());
    expect((int) $reloaded->noteable_id)->toBe($lead->id);
    expect((int) $reloaded->user_updated_id)->toBe($user->id);

    expect(Activity::query()
        ->where('timelineable_type', $lead->getMorphClass())
        ->where('timelineable_id', $lead->id)
        ->where('recordable_type', $note->getMorphClass())
        ->where('recordable_id', $note->id)
        ->exists())->toBeTrue();
});

it('deleteNote() soft-deletes the note via the owner relation', function () {
    $rm = leadNotesFocusedRm();

    $user = User::create([
        'name' => 'Focused Note Deleter',
        'email' => 'focused-deleter-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    auth()->login($user);

    $lead = Lead::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Focused lead — delete',
    ]);

    $note = $lead->notes()->create([
        'content' => 'To be soft-deleted',
        'noted_at' => now(),
        'user_created_id' => $user->id,
    ]);

    $rm->ownerRecord = $lead->fresh();

    $rm->deleteNote((int) $note->id);

    expect(Note::query()->find($note->id))->toBeNull();
    expect(Note::withTrashed()->find($note->id))->not->toBeNull();
    expect(Note::withTrashed()->find($note->id)->deleted_at)->not->toBeNull();
});

// AC (5) Blade source assertions.

it('the lead-notes Blade view contains the expected structural markers', function () {
    $bladePath = dirname(__DIR__, 2) . '/resources/views/crm-notes.blade.php';
    expect(file_exists($bladePath))->toBeTrue();

    $blade = file_get_contents($bladePath);

    // Add-note form wired to createNote with the inline state bindings.
    expect($blade)->toContain('@if ($editingId === null)');
    expect($blade)->toContain('wire:submit="createNote"');
    expect($blade)->toContain('wire:model="data.content"');
    expect($blade)->toContain('wire:model="data.noted_at"');

    // Notes loop (cards) sorted by created_at desc.
    // US-009: rows now come from the RollsUpRelatedActivity concern (still
    // newest-first) so the `show_related_activity` setting is honoured.
    expect($blade)->toContain('$this->relatedActivityRows()');
    expect($blade)->toContain('@forelse');
    expect($blade)->toContain('@empty');

    // Three-dot dropdown wired to editNote / deleteNote per row.
    expect($blade)->toContain('x-data="{ open: false }"');
    expect($blade)->toContain('crm-card-dropdown');
    expect($blade)->toContain('wire:click="editNote({{ $note->id }})"');
    expect($blade)->toContain('wire:click="deleteNote({{ $note->id }})"');

    // Dark-mode styles now live in the shared lead-card-styles partial.
    expect($blade)->toContain("@include('laravel-crm-filament::partials.crm-card-styles')");
    expect($blade)->not->toContain('@once');
    expect($blade)->not->toContain('--crm-note-bg:');

    $partialPath = dirname(__DIR__, 2) . '/resources/views/partials/crm-card-styles.blade.php';
    expect(file_exists($partialPath))->toBeTrue();

    $partial = file_get_contents($partialPath);
    expect($partial)->toContain('@once');
    expect($partial)->toContain('<style>');
    expect($partial)->toContain('@endonce');
    expect($partial)->toContain('--crm-card-bg:');
    expect($partial)->toContain('html.dark .crm-card-area-notes');
});
