<?php

use Filament\Forms;
use Filament\Schemas\Schema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Activity;
use VentureDrake\LaravelCrm\Models\File;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmFilesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\FilesRelationManager;
use VentureDrake\LaravelCrmFilament\Resources\Leads\Pages\ViewLead;
use VentureDrake\LaravelCrmFilament\Support\FileDownload;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

// AC (1) Inheritance.

it('extends FilesRelationManager', function () {
    expect(is_subclass_of(CrmFilesRelationManager::class, FilesRelationManager::class))->toBeTrue();
});

// AC (2) $view points at the lead-files template.

it('overrides the $view property to point at the lead-files Blade template', function () {
    $ref = new ReflectionClass(CrmFilesRelationManager::class);
    $prop = $ref->getProperty('view');
    $prop->setAccessible(true);

    expect($prop->getDeclaringClass()->getName())->toBe(CrmFilesRelationManager::class);

    $rm = $ref->newInstanceWithoutConstructor();
    expect($prop->getValue($rm))->toBe('laravel-crm-filament::crm-files');
});

// AC (3) form() returns a single FileUpload field with no label and no title field.

it('returns a 1-field form schema with FileUpload only, no label and no title', function () {
    $rm = (new ReflectionClass(CrmFilesRelationManager::class))->newInstanceWithoutConstructor();
    $schema = $rm->form(Schema::make($rm));

    $components = $schema->getComponents();
    $names = array_values(array_map(fn ($c) => $c->getName(), $components));

    expect($names)->toBe(['file']);
    expect($components[0])->toBeInstanceOf(Forms\Components\FileUpload::class);

    // Label hidden via ->hiddenLabel() — Filament's public getter is isLabelHidden().
    expect($components[0]->isLabelHidden())->toBeTrue();

    expect($schema->getStatePath())->toBe('data');
});

it('inherits the parent table configuration', function () {
    $ref = new ReflectionClass(CrmFilesRelationManager::class);

    expect($ref->hasMethod('table'))->toBeTrue();

    // US-009: the RollsUpRelatedActivity concern composes a table() that
    // delegates to the parent and appends the "Related" badge column, so the
    // declaring class is now the Crm* subclass the trait is used by.
    expect($ref->getMethod('table')->getDeclaringClass()->getName())
        ->toBe(CrmFilesRelationManager::class);
    expect(($ref->getMethod('table')->getFileName()))
        ->toContain('RollsUpRelatedActivity.php');
});

it('inherits the parent relationship binding to files morphMany', function () {
    $ref = new ReflectionClass(CrmFilesRelationManager::class);
    $prop = $ref->getProperty('relationship');
    $prop->setAccessible(true);

    expect($prop->getValue())->toBe('files');
});

it('declares createFile/downloadFile/deleteFile lifecycle methods (no edit/update/cancelEdit)', function () {
    $ref = new ReflectionClass(CrmFilesRelationManager::class);

    expect($ref->hasMethod('createFile'))->toBeTrue();
    expect($ref->getMethod('createFile')->getDeclaringClass()->getName())
        ->toBe(CrmFilesRelationManager::class);

    expect($ref->hasMethod('downloadFile'))->toBeTrue();
    expect($ref->getMethod('downloadFile')->getDeclaringClass()->getName())
        ->toBe(CrmFilesRelationManager::class);

    expect($ref->hasMethod('deleteFile'))->toBeTrue();
    expect($ref->getMethod('deleteFile')->getDeclaringClass()->getName())
        ->toBe(CrmFilesRelationManager::class);

    // AC: no edit mode — these methods MUST NOT be declared.
    expect($ref->hasMethod('editFile'))->toBeFalse();
    expect($ref->hasMethod('updateFile'))->toBeFalse();
    expect($ref->hasMethod('cancelEdit'))->toBeFalse();
});

// AC (4) CRUD round-trip against a real Lead owner record.

it('createFile() stores the upload through core and persists a File row via the morphMany', function () {
    Storage::fake('local');

    $user = actingAsCrmAdmin();

    $lead = Lead::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Focused lead — upload',
    ]);

    $component = livewire(CrmFilesRelationManager::class, [
        'ownerRecord' => $lead,
        'pageClass' => ViewLead::class,
    ])
        ->set('data.file', UploadedFile::fake()->create('example.pdf', 14, 'application/pdf'))
        ->call('createFile')
        ->assertHasNoErrors();

    $file = File::query()->where('name', 'example.pdf')->first();
    expect($file)->not->toBeNull();
    expect($file->fileable_type)->toBe($lead->getMorphClass());
    expect((int) $file->fileable_id)->toBe($lead->id);
    expect($file->file)->toMatch('#^laravel-crm/lead/' . $lead->id . '/files/[A-Za-z0-9]{40}\.pdf$#');
    expect((int) $file->filesize)->toBe(14 * 1024);
    expect($file->mime)->toBe('application/pdf');
    expect($file->format)->toBe('pdf');
    expect($file->disk)->toBe('local');
    expect($file->external_id)->not->toBeEmpty();
    expect((int) $file->user_created_id)->toBe($user->id);
    Storage::disk('local')->assertExists($file->file);

    // Activity row written for the timeline.
    expect(Activity::query()
        ->where('timelineable_type', $lead->getMorphClass())
        ->where('timelineable_id', $lead->id)
        ->where('recordable_type', $file->getMorphClass())
        ->where('recordable_id', $file->id)
        ->exists())->toBeTrue();

    // Form state reset.
    expect($component->get('data.file'))->toBeEmpty();
});

it('downloadFile() returns the panel download route for the file', function () {
    Storage::fake('local');
    Storage::disk('local')->put('laravel-crm/lead/1/files/downloadable.txt', 'hello');

    $user = User::create([
        'name' => 'Download User',
        'email' => 'download-user-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    auth()->login($user);

    $lead = Lead::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Focused lead — download',
    ]);

    $file = $lead->files()->create([
        'external_id' => (string) Str::uuid(),
        'file' => 'laravel-crm/lead/1/files/downloadable.txt',
        'name' => 'downloadable.txt',
        'disk' => 'local',
    ]);

    $rm = new CrmFilesRelationManager;
    $rm->ownerRecord = $lead->fresh();

    expect($rm->downloadFile((int) $file->id))
        ->toBe(route(FileDownload::routeName(), ['file' => $file->external_id]));
});

it('downloadFile() returns null for a record that does not belong to the owner lead', function () {
    $user = User::create([
        'name' => 'Mismatch User',
        'email' => 'mismatch-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    auth()->login($user);

    $leadA = Lead::create(['external_id' => (string) Str::uuid(), 'title' => 'Lead A']);
    $leadB = Lead::create(['external_id' => (string) Str::uuid(), 'title' => 'Lead B']);

    $foreignFile = $leadB->files()->create([
        'file' => 'leads/foreign.txt',
        'name' => 'foreign.txt',
        'disk' => 'local',
    ]);

    $rm = new CrmFilesRelationManager;
    $rm->ownerRecord = $leadA->fresh();

    expect($rm->downloadFile((int) $foreignFile->id))->toBeNull();
});

it('deleteFile() soft-deletes the file via the owner relation', function () {
    $rm = new CrmFilesRelationManager;

    $user = User::create([
        'name' => 'Focused File Deleter',
        'email' => 'focused-deleter-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    auth()->login($user);

    $lead = Lead::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Focused lead — delete',
    ]);

    $file = $lead->files()->create([
        'file' => 'leads/to-delete.txt',
        'name' => 'to-delete.txt',
        'disk' => 'local',
    ]);

    $rm->ownerRecord = $lead->fresh();

    $rm->deleteFile((int) $file->id);

    expect(File::query()->find($file->id))->toBeNull();
    expect(File::withTrashed()->find($file->id))->not->toBeNull();
    expect(File::withTrashed()->find($file->id)->deleted_at)->not->toBeNull();
});

// AC (5) Blade source assertions.

it('the lead-files Blade view contains the expected structural markers', function () {
    $bladePath = dirname(__DIR__, 2) . '/resources/views/crm-files.blade.php';
    expect(file_exists($bladePath))->toBeTrue();

    $blade = file_get_contents($bladePath);

    // Upload card wired to createFile.
    expect($blade)->toContain('wire:submit="createFile"');
    expect($blade)->toContain('{{ $this->form }}');
    expect($blade)->toContain('laravel-crm-filament::labels.sections.add_file');

    // Files loop sorted by created_at desc.
    // US-009: rows now come from the RollsUpRelatedActivity concern (still
    // newest-first) so the `show_related_activity` setting is honoured.
    expect($blade)->toContain('$this->relatedActivityRows()');
    expect($blade)->toContain('@forelse');
    expect($blade)->toContain('@empty');

    // Footer pill shows filesize + mime.
    expect($blade)->toContain('$file->filesize');
    expect($blade)->toContain('$file->mime');
    expect($blade)->toContain('crm-card-pill');

    // Three-dot dropdown with Download (anchor) + Delete (wire:click).
    expect($blade)->toContain('x-data="{ open: false }"');
    expect($blade)->toContain('crm-card-dropdown');
    expect($blade)->toContain('$this->downloadFile($file->id)');
    expect($blade)->toContain('wire:click="deleteFile({{ $file->id }})"');
    expect($blade)->toContain('laravel-crm-filament::labels.actions.download');

    // NO edit markers — the AC explicitly says download + delete only.
    expect($blade)->not->toContain('wire:click="editFile');
    expect($blade)->not->toContain('wire:click="updateFile');
    expect($blade)->not->toContain('wire:click="cancelEdit');

    // Shared partial is included; no inline @once <style>.
    expect($blade)->toContain("@include('laravel-crm-filament::partials.crm-card-styles')");
    expect($blade)->not->toContain('@once');

    // Empty state copy.
    expect($blade)->toContain('No files yet.');

    // Partial declares the new .crm-card-area-files selector.
    $partialPath = dirname(__DIR__, 2) . '/resources/views/partials/crm-card-styles.blade.php';
    $partial = file_get_contents($partialPath);
    expect($partial)->toContain('.crm-card-area-files');
    expect($partial)->toContain('html.dark .crm-card-area-files');
});
