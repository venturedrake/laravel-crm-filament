<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\Note;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmNotesRelationManager;
use VentureDrake\LaravelCrmFilament\Resources\Leads\Pages\ViewLead;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\Pages\ViewOrganization;
use VentureDrake\LaravelCrmFilament\Resources\People\Pages\ViewPerson;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * Regression for #6: adding a note from a record's Notes tab failed with
 * "Field 'external_id' doesn't have a default value". Core's crm_notes.external_id
 * is NOT NULL with no default and core's NoteObserver does not fill it, so the
 * panel has to generate it, as core's own NoteRelated::save() does.
 *
 * The shared TestSchema declares the column nullable, which hid the bug. This
 * file tightens it to match the installed schema, and mounts the real relation
 * manager so the actual createNote() runs.
 */
beforeEach(function () {
    RoleSeeder::seed();

    Schema::table(config('laravel-crm.db_table_prefix') . 'notes', function (Blueprint $table) {
        $table->string('external_id')->nullable(false)->change();
    });
});

function crmNotesExternalIdUser(): User
{
    $user = User::create([
        'name' => 'Notes Tester',
        'email' => 'notes-external-id-' . Str::random(8) . '@example.com',
        'password' => bcrypt('secret'),
    ]);

    foreach (['view', 'create', 'edit', 'delete'] as $action) {
        foreach (['notes', 'people', 'leads', 'organizations'] as $entity) {
            $user->givePermissionTo("{$action} crm {$entity}");
        }
    }

    return $user->fresh();
}

// CrmNotesRelationManager is shared by every owner type.
dataset('crmNotesExternalIdOwners', [
    'Person' => [Person::class, ['first_name' => 'Note', 'last_name' => 'Owner'], ViewPerson::class],
    'Lead' => [Lead::class, ['title' => 'Note owner lead'], ViewLead::class],
    'Organization' => [Organization::class, ['name' => 'Note owner org'], ViewOrganization::class],
]);

it('creates a note from the Notes tab with a generated external_id', function (string $model, array $attributes, string $page): void {
    $this->actingAs(crmNotesExternalIdUser());

    $owner = $model::create(['external_id' => (string) Str::uuid(), ...$attributes]);

    $content = 'QA Filament note <test> & "quotes"';

    livewire(CrmNotesRelationManager::class, [
        'ownerRecord' => $owner,
        'pageClass' => $page,
    ])
        ->fillForm([
            'content' => $content,
            'noted_at' => '2026-11-17 04:00',
        ])
        ->call('createNote')
        ->assertHasNoFormErrors();

    $notes = Note::query()->where('content', $content)->get();

    expect($notes)->toHaveCount(1);

    $note = $notes->first();

    expect($note->noteable_type)->toBe($owner->getMorphClass())
        ->and((int) $note->noteable_id)->toBe((int) $owner->id)
        ->and($note->noted_at->format('Y-m-d H:i:s'))->toBe('2026-11-17 04:00:00')
        ->and(Str::isUuid($note->external_id))->toBeTrue()
        ->and($owner->fresh()->notes->pluck('id')->all())->toContain($note->id);
})->with('crmNotesExternalIdOwners');

it('gives each new note its own external_id', function (): void {
    $this->actingAs(crmNotesExternalIdUser());

    $person = Person::create([
        'external_id' => (string) Str::uuid(),
        'first_name' => 'Note',
        'last_name' => 'Owner',
    ]);

    livewire(CrmNotesRelationManager::class, [
        'ownerRecord' => $person,
        'pageClass' => ViewPerson::class,
    ])
        ->fillForm(['content' => 'First note'])
        ->call('createNote')
        ->assertHasNoFormErrors()
        // set() mirrors the card's wire:model input. A second fillForm() goes
        // through Filament's test-only skipRender() path, which tries to render
        // the inherited table's filter form, and this view has no table.
        ->set('data.content', 'Second note')
        ->call('createNote')
        ->assertHasNoFormErrors();

    $ids = $person->fresh()->notes->pluck('external_id');

    expect($ids)->toHaveCount(2)
        ->and($ids->unique())->toHaveCount(2)
        ->and($ids->every(fn ($id) => Str::isUuid($id)))->toBeTrue();
});
