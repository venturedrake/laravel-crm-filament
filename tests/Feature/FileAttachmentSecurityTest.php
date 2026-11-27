<?php

use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\FileUpload;
use Filament\Http\Middleware\Authenticate;
use Filament\Schemas\Schema;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use VentureDrake\LaravelCrm\Models\Customer;
use VentureDrake\LaravelCrm\Models\Deal;
use VentureDrake\LaravelCrm\Models\File;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Support\AttachmentUpload;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmFilesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\FilesRelationManager;
use VentureDrake\LaravelCrmFilament\Resources\Customers\Pages\EditCustomer;
use VentureDrake\LaravelCrmFilament\Resources\Files\FileResource;
use VentureDrake\LaravelCrmFilament\Resources\Leads\Pages\ViewLead;
use VentureDrake\LaravelCrmFilament\Support\AttachmentStore;
use VentureDrake\LaravelCrmFilament\Support\FileDownload;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * Core 2.7.1 security release: attachments go to a private disk, with core's
 * type and size checks and a hashed filename, and download through an
 * authenticated, policy-checked panel route.
 *
 * Before this the panel's own FileUpload fields wrote straight to the public
 * disk, kept the client's filename (so an uploaded `.html` or `.svg` was served
 * from /storage as-is), accepted any type and size, and put every owner's
 * files under `laravel-crm/lead/`.
 */
beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
    Storage::fake(FileUploadConfiguration::disk());
});

function attachmentLead(): Lead
{
    return Lead::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Attachment lead',
    ]);
}

function attachmentStoredFile(array $attributes = []): File
{
    $lead = attachmentLead();

    $file = $lead->files()->create([
        'external_id' => (string) Str::uuid(),
        'file' => 'laravel-crm/lead/' . $lead->id . '/files/' . Str::random(40) . '.pdf',
        'name' => 'quote.pdf',
        'mime' => 'application/pdf',
        'disk' => 'local',
        ...$attributes,
    ]);

    return $file;
}

// --- Uploads -----------------------------------------------------------------

it('builds the upload field without Filament storage, public disk or client filenames', function (string $class) {
    $rm = (new ReflectionClass($class))->newInstanceWithoutConstructor();

    $field = collect($rm->form(Schema::make($rm))->getComponents())
        ->first(fn ($component) => $component instanceof FileUpload);

    expect($field->shouldStoreFiles())->toBeFalse()
        ->and($field->shouldPreserveFilenames())->toBeFalse()
        ->and($field->getMaxSize())->toBe(AttachmentUpload::MAX_SIZE_KB)
        ->and($field->getAcceptedFileTypes())->toContain('application/pdf')
        ->and($field->getAcceptedFileTypes())->not->toContain('text/html')
        ->and($field->getAcceptedFileTypes())->not->toContain('image/svg+xml');
})->with([
    'files card' => [CrmFilesRelationManager::class],
    'create modal' => [FilesRelationManager::class],
]);

it('stores a PDF on the private attachments disk under the owner directory with a hashed name', function (string $modelClass, array $extra) {
    config(['laravel-crm.attachments.disk' => 'local']);
    $user = actingAsCrmAdmin();

    $owner = $modelClass::create(['external_id' => (string) Str::uuid(), ...$extra]);

    $file = AttachmentStore::storeFor(
        $owner,
        crmTemporaryUpload(UploadedFile::fake()->create('Signed Contract.pdf', 30, 'application/pdf')),
        ['title' => 'Signed'],
        'data.file',
    );

    $directory = 'laravel-crm/' . strtolower(class_basename($owner)) . '/' . $owner->id . '/files/';

    expect($file->file)->toStartWith($directory)
        ->and(basename($file->file))->toMatch('/^[A-Za-z0-9]{40}\.pdf$/')
        ->and($file->file)->not->toContain('Signed Contract')
        ->and($file->disk)->toBe('local')
        ->and($file->name)->toBe('Signed Contract.pdf')
        ->and($file->mime)->toBe('application/pdf')
        ->and((int) $file->filesize)->toBe(30 * 1024)
        ->and($file->title)->toBe('Signed')
        ->and($file->format)->toBe('pdf')
        ->and((int) $file->user_created_id)->toBe($user->id);

    if ($modelClass !== Lead::class) {
        expect($file->file)->not->toContain('/lead/');
    }

    Storage::disk('local')->assertExists($file->file);
    Storage::disk('public')->assertMissing($file->file);
})->with([
    'Lead' => [Lead::class, ['title' => 'Owner lead']],
    'Deal' => [Deal::class, ['title' => 'Owner deal']],
    'Person' => [Person::class, ['first_name' => 'Owner', 'last_name' => 'Person']],
]);

it('writes to whichever disk laravel-crm.attachments.disk names', function () {
    Storage::fake('crm-private');
    config(['laravel-crm.attachments.disk' => 'crm-private']);
    actingAsCrmAdmin();

    $file = AttachmentStore::storeFor(
        attachmentLead(),
        crmTemporaryUpload(UploadedFile::fake()->create('a.pdf', 5, 'application/pdf')),
        [],
        'data.file',
    );

    expect($file->disk)->toBe('crm-private');
    Storage::disk('crm-private')->assertExists($file->file);
});

it('rejects a disallowed or disguised file server-side and creates no row', function (string $name, string $mime) {
    actingAsCrmAdmin();

    expect(fn () => AttachmentStore::storeFor(
        attachmentLead(),
        crmTemporaryUpload(UploadedFile::fake()->create($name, 4, $mime)),
        [],
        'data.file',
    ))->toThrow(ValidationException::class);

    expect(File::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(Storage::disk('public')->allFiles())->toBe([]);
})->with([
    'html' => ['page.html', 'text/html'],
    'svg' => ['logo.svg', 'image/svg+xml'],
    'php' => ['shell.php', 'application/x-httpd-php'],
    // Allowed MIME, disallowed extension: core checks the extension separately.
    'php named as pdf content' => ['shell.php', 'application/pdf'],
    // Allowed extension, content sniffed as something else.
    'renamed executable' => ['invoice.pdf', 'application/x-msdownload'],
]);

it('rejects a file over 10 MB', function () {
    actingAsCrmAdmin();

    expect(fn () => AttachmentStore::storeFor(
        attachmentLead(),
        crmTemporaryUpload(UploadedFile::fake()->create('big.pdf', AttachmentUpload::MAX_SIZE_KB + 1, 'application/pdf')),
        [],
        'data.file',
    ))->toThrow(ValidationException::class);

    expect(File::query()->count())->toBe(0);
});

it('rejects a client-supplied path string in place of an upload', function () {
    actingAsCrmAdmin();

    // Filament passes existing-path strings through unchanged; this one would
    // otherwise attach a file the user never uploaded.
    expect(fn () => AttachmentStore::storeFor(attachmentLead(), 'laravel-crm/lead/9/files/secret.pdf', [], 'data.file'))
        ->toThrow(ValidationException::class);

    expect(File::query()->count())->toBe(0);
});

it('reports a failed disk write as a validation error and leaves no orphan row', function () {
    // A local disk whose root cannot be created: every write fails.
    config([
        'filesystems.disks.crm-broken' => ['driver' => 'local', 'root' => '/dev/null/laravel-crm'],
        'laravel-crm.attachments.disk' => 'crm-broken',
    ]);
    actingAsCrmAdmin();

    try {
        AttachmentStore::storeFor(
            attachmentLead(),
            crmTemporaryUpload(UploadedFile::fake()->create('a.pdf', 5, 'application/pdf')),
            [],
            'data.file',
        );

        $this->fail('Expected a ValidationException');
    } catch (ValidationException $exception) {
        expect($exception->errors()['data.file'] ?? null)
            ->toBe([ucfirst(__('laravel-crm::lang.file_upload_failed'))]);
    }

    expect(File::query()->count())->toBe(0);
});

it('shows a rejected upload under the files card field', function () {
    actingAsCrmAdmin();

    $lead = attachmentLead();

    livewire(CrmFilesRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
        ->set('data.file', UploadedFile::fake()->create('page.html', 4, 'text/html'))
        ->call('createFile')
        ->assertHasErrors(['data.file']);

    expect(File::query()->count())->toBe(0);
});

it('shows a rejected upload under the create modal field', function () {
    actingAsCrmAdmin();

    $customer = Customer::create(['external_id' => (string) Str::uuid(), 'name' => 'Attachment customer']);

    livewire(FilesRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => EditCustomer::class])
        ->callAction(TestAction::make('create')->table(), data: [
            // Passes Filament's MIME hint; core's extension check stops it.
            'file' => UploadedFile::fake()->create('shell.php', 4, 'application/pdf'),
        ])
        ->assertHasErrors(['mountedActions.0.data.file']);

    expect(File::query()->count())->toBe(0);
});

it('stores a Customer upload from the create modal through core', function () {
    actingAsCrmAdmin();

    $customer = Customer::create(['external_id' => (string) Str::uuid(), 'name' => 'Attachment customer']);

    livewire(FilesRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => EditCustomer::class])
        ->callAction(TestAction::make('create')->table(), data: [
            'file' => UploadedFile::fake()->create('terms.pdf', 8, 'application/pdf'),
            'title' => 'Terms',
            'format' => 'contract',
        ])
        ->assertHasNoErrors();

    $file = File::query()->sole();

    expect($file->file)->toStartWith('laravel-crm/customer/' . $customer->id . '/files/')
        ->and($file->disk)->toBe('local')
        ->and($file->name)->toBe('terms.pdf')
        ->and($file->title)->toBe('Terms')
        ->and($file->format)->toBe('contract');
    Storage::disk('local')->assertExists($file->file);
});

// --- Downloads ---------------------------------------------------------------

it('registers the download route inside the panel auth middleware', function () {
    expect(FileDownload::routeName())->toBe('filament.admin.laravel-crm-filament.file-download');

    $route = app('router')->getRoutes()->getByName(FileDownload::routeName());

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain(Authenticate::class);
});

it('streams the file as an attachment to a user who may view it', function () {
    actingAsCrmAdmin();

    $file = attachmentStoredFile();
    Storage::disk('local')->put($file->file, '%PDF-1.4 private bytes');

    $response = $this->get(FileDownload::urlFor($file));

    $response->assertOk()->assertDownload('quote.pdf');

    expect($response->streamedContent())->toBe('%PDF-1.4 private bytes')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Cache-Control'))->toContain('private')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('403s a panel user without view permission on files', function () {
    RoleSeeder::seed();

    $this->actingAs(User::create([
        'name' => 'File Stranger',
        'email' => 'file-stranger-' . Str::random(8) . '@example.com',
        'password' => bcrypt('secret'),
    ])->fresh());

    $file = attachmentStoredFile();
    Storage::disk('local')->put($file->file, 'secret');

    $this->get(FileDownload::urlFor($file))->assertForbidden();
});

it('404s a row whose object is missing, and an unknown file', function () {
    actingAsCrmAdmin();

    $file = attachmentStoredFile();

    $this->get(FileDownload::urlFor($file))->assertNotFound();
    $this->get(route(FileDownload::routeName(), ['file' => (string) Str::uuid()]))->assertNotFound();
});

it('rejects a guest', function () {
    $file = attachmentStoredFile();
    Storage::disk('local')->put($file->file, 'secret');

    // As in DocumentPreviewTest: the testbench app has no `login` route to
    // redirect to, so assert the auth middleware's exception instead.
    $this->withoutExceptionHandling();

    expect(fn () => $this->get(FileDownload::urlFor($file)))
        ->toThrow(AuthenticationException::class);
});

it('still downloads a legacy row on the public disk, and one with no disk recorded', function (string $disk) {
    actingAsCrmAdmin();

    $file = attachmentStoredFile([
        'file' => 'laravel-crm/lead/1/files/legacy name.pdf',
        'name' => 'legacy name.pdf',
        'disk' => $disk,
    ]);
    Storage::disk('public')->put($file->file, 'legacy bytes');

    $response = $this->get(FileDownload::urlFor($file));

    $response->assertOk()->assertDownload('legacy name.pdf');
    expect($response->streamedContent())->toBe('legacy bytes');
})->with(['public' => 'public', 'no disk recorded' => '']);

it('serves a legacy .html upload as a download, never inline', function () {
    actingAsCrmAdmin();

    $file = attachmentStoredFile([
        'file' => 'laravel-crm/lead/1/files/page.html',
        'name' => 'page.html',
        'disk' => 'public',
    ]);
    Storage::disk('public')->put($file->file, '<script>alert(1)</script>');

    $response = $this->get(FileDownload::urlFor($file));

    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment');
});

it('gives a legacy row without an external_id one, so it can be routed', function () {
    actingAsCrmAdmin();

    $file = attachmentStoredFile(['external_id' => null]);
    Storage::disk('local')->put($file->file, 'bytes');

    $url = FileDownload::urlFor($file);

    expect($file->fresh()->external_id)->not->toBeEmpty()
        ->and($url)->toBe(route(FileDownload::routeName(), ['file' => $file->fresh()->external_id]));

    $this->get($url)->assertOk();
});

it('points every download builder at the route', function () {
    $file = attachmentStoredFile();
    $expected = route(FileDownload::routeName(), ['file' => $file->external_id]);

    // FileResource keeps its "hide when the object is gone" rule.
    expect(FileResource::buildDownloadUrl($file))->toBeNull();

    Storage::disk('local')->put($file->file, 'bytes');

    $build = fn (string $class, string $method) => (new ReflectionMethod($class, $method))->invoke(null, $file);

    expect(FileResource::buildDownloadUrl($file))->toBe($expected)
        ->and($build(FilesRelationManager::class, 'buildDownloadUrl'))->toBe($expected)
        ->and($build(CrmFilesRelationManager::class, 'buildOwnerDownloadUrl'))->toBe($expected);

    expect(FileResource::buildDownloadUrl(attachmentStoredFile(['file' => ''])))->toBeNull();
});
