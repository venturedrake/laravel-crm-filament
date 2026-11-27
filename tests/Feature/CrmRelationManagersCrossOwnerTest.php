<?php

use Filament\Notifications\Notification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use VentureDrake\LaravelCrm\Models\Call;
use VentureDrake\LaravelCrm\Models\Deal;
use VentureDrake\LaravelCrm\Models\Delivery;
use VentureDrake\LaravelCrm\Models\File;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\Lunch;
use VentureDrake\LaravelCrm\Models\Meeting;
use VentureDrake\LaravelCrm\Models\Note;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\PurchaseOrder;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrm\Models\Task;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmCallsRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmFilesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmLunchesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmMeetingsRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmNotesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmTasksRelationManager;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

/**
 * Cross-owner regression test for US-008. The new Crm* family of inline-card
 * RelationManagers (Notes/Tasks/Calls/Meetings/Lunches/Files) is registered on
 * 8 sibling primary resources alongside Lead. The supplementary test-tables
 * migration must support polymorphic activity writes from every one of those
 * owner families — this file is the gate.
 */
dataset('crmActivityOwners', [
    'Deal' => [Deal::class, ['title' => 'Cross-owner deal']],
    'Quote' => [Quote::class, ['title' => 'Cross-owner quote']],
    'Order' => [Order::class, []],
    'Invoice' => [Invoice::class, []],
    'PurchaseOrder' => [PurchaseOrder::class, []],
    'Delivery' => [Delivery::class, []],
    'Person' => [Person::class, ['first_name' => 'Cross', 'last_name' => 'Owner']],
    'Organization' => [Organization::class, ['name' => 'Cross-owner Org']],
]);

function crmCrossOwnerLoginUser(): User
{
    $user = User::create([
        'name' => 'Cross-owner Author',
        'email' => 'cross-owner-' . Str::random(8) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    auth()->login($user);

    return $user;
}

function crmCrossOwnerMake(string $modelClass, array $extra = [])
{
    return $modelClass::create(array_merge([
        'external_id' => (string) Str::uuid(),
    ], $extra));
}

function crmCrossOwnerNotesRm(): CrmNotesRelationManager
{
    return new class extends CrmNotesRelationManager
    {
        public function createNote(): void
        {
            $data = $this->data ?? [];

            $this->getOwnerRecord()->notes()->create([
                'content' => $data['content'],
                'noted_at' => $data['noted_at'] ?? null,
                'user_created_id' => auth()->id(),
            ]);

            Notification::make()->title('Note added')->success()->send();
        }
    };
}

function crmCrossOwnerTasksRm(): CrmTasksRelationManager
{
    return new class extends CrmTasksRelationManager
    {
        public function createTask(): void
        {
            $data = $this->data ?? [];

            $this->getOwnerRecord()->tasks()->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'due_at' => $data['due_at'] ?? null,
                'user_owner_id' => $data['user_owner_id'] ?? auth()->id(),
                'user_assigned_id' => $data['user_assigned_id'] ?? null,
                'user_created_id' => auth()->id(),
            ]);

            Notification::make()->title('Task added')->success()->send();
        }
    };
}

function crmCrossOwnerCallsRm(): CrmCallsRelationManager
{
    return new class extends CrmCallsRelationManager
    {
        public function createCall(): void
        {
            $data = $this->data ?? [];

            $this->getOwnerRecord()->calls()->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'start_at' => $data['start_at'] ?? null,
                'finish_at' => $data['finish_at'] ?? null,
                'user_owner_id' => $data['user_owner_id'] ?? auth()->id(),
                'user_assigned_id' => $data['user_assigned_id'] ?? null,
                'user_created_id' => auth()->id(),
            ]);

            Notification::make()->title('Call added')->success()->send();
        }
    };
}

function crmCrossOwnerMeetingsRm(): CrmMeetingsRelationManager
{
    return new class extends CrmMeetingsRelationManager
    {
        public function createMeeting(): void
        {
            $data = $this->data ?? [];

            $this->getOwnerRecord()->meetings()->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'start_at' => $data['start_at'] ?? null,
                'finish_at' => $data['finish_at'] ?? null,
                'location' => $data['location'] ?? null,
                'user_owner_id' => $data['user_owner_id'] ?? auth()->id(),
                'user_assigned_id' => $data['user_assigned_id'] ?? null,
                'user_created_id' => auth()->id(),
            ]);

            Notification::make()->title('Meeting added')->success()->send();
        }
    };
}

function crmCrossOwnerLunchesRm(): CrmLunchesRelationManager
{
    return new class extends CrmLunchesRelationManager
    {
        public function createLunch(): void
        {
            $data = $this->data ?? [];

            $this->getOwnerRecord()->lunches()->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'start_at' => $data['start_at'] ?? null,
                'finish_at' => $data['finish_at'] ?? null,
                'location' => $data['location'] ?? null,
                'user_owner_id' => $data['user_owner_id'] ?? auth()->id(),
                'user_assigned_id' => $data['user_assigned_id'] ?? null,
                'user_created_id' => auth()->id(),
            ]);

            Notification::make()->title('Lunch added')->success()->send();
        }
    };
}

/**
 * Headless stand-in for the mounted card: reads `$this->data` instead of the
 * Filament form, then runs the real store path (attachUpload() ->
 * AttachmentStore -> core's AttachmentUpload).
 */
function crmCrossOwnerFilesRm(): CrmFilesRelationManager
{
    return new class extends CrmFilesRelationManager
    {
        public function createFile(): void
        {
            $this->attachUpload($this->data['file'] ?? null);
        }

        protected function resetUploadForm(): void
        {
            $this->data = ['file' => null];
        }
    };
}

it('CrmNotesRelationManager creates a Note attached to the new owner family', function (string $modelClass, array $extra) {
    $user = crmCrossOwnerLoginUser();
    $owner = crmCrossOwnerMake($modelClass, $extra);

    $rm = crmCrossOwnerNotesRm();
    $rm->ownerRecord = $owner->fresh();
    $rm->data = [
        'content' => 'Cross-owner inline note for ' . $modelClass,
        'noted_at' => now()->setSecond(0),
    ];
    $rm->createNote();

    $note = Note::query()->where('content', 'Cross-owner inline note for ' . $modelClass)->first();
    expect($note)->not->toBeNull();
    expect($note->noteable_type)->toBe($owner->getMorphClass());
    expect((int) $note->noteable_id)->toBe((int) $owner->id);
    expect((int) $note->user_created_id)->toBe($user->id);
})->with('crmActivityOwners');

it('CrmTasksRelationManager creates a Task attached to the new owner family', function (string $modelClass, array $extra) {
    $user = crmCrossOwnerLoginUser();
    $owner = crmCrossOwnerMake($modelClass, $extra);

    $rm = crmCrossOwnerTasksRm();
    $rm->ownerRecord = $owner->fresh();
    $rm->data = [
        'name' => 'Cross-owner task ' . $modelClass,
        'description' => 'desc',
        'due_at' => now()->setSecond(0),
    ];
    $rm->createTask();

    $task = Task::query()->where('name', 'Cross-owner task ' . $modelClass)->first();
    expect($task)->not->toBeNull();
    expect($task->taskable_type)->toBe($owner->getMorphClass());
    expect((int) $task->taskable_id)->toBe((int) $owner->id);
    expect((int) $task->user_owner_id)->toBe($user->id);
    expect((int) $task->user_created_id)->toBe($user->id);
})->with('crmActivityOwners');

it('CrmCallsRelationManager creates a Call attached to the new owner family', function (string $modelClass, array $extra) {
    $user = crmCrossOwnerLoginUser();
    $owner = crmCrossOwnerMake($modelClass, $extra);

    $rm = crmCrossOwnerCallsRm();
    $rm->ownerRecord = $owner->fresh();
    $rm->data = [
        'name' => 'Cross-owner call ' . $modelClass,
        'description' => 'desc',
        'start_at' => now()->setSecond(0),
        'finish_at' => null,
    ];
    $rm->createCall();

    $call = Call::query()->where('name', 'Cross-owner call ' . $modelClass)->first();
    expect($call)->not->toBeNull();
    expect($call->callable_type)->toBe($owner->getMorphClass());
    expect((int) $call->callable_id)->toBe((int) $owner->id);
    expect((int) $call->user_owner_id)->toBe($user->id);
})->with('crmActivityOwners');

it('CrmMeetingsRelationManager creates a Meeting attached to the new owner family', function (string $modelClass, array $extra) {
    $user = crmCrossOwnerLoginUser();
    $owner = crmCrossOwnerMake($modelClass, $extra);

    $rm = crmCrossOwnerMeetingsRm();
    $rm->ownerRecord = $owner->fresh();
    $rm->data = [
        'name' => 'Cross-owner meeting ' . $modelClass,
        'description' => 'desc',
        'start_at' => now()->setSecond(0),
        'finish_at' => null,
        'location' => 'HQ',
    ];
    $rm->createMeeting();

    $meeting = Meeting::query()->where('name', 'Cross-owner meeting ' . $modelClass)->first();
    expect($meeting)->not->toBeNull();
    expect($meeting->meetingable_type)->toBe($owner->getMorphClass());
    expect((int) $meeting->meetingable_id)->toBe((int) $owner->id);
    expect((int) $meeting->user_owner_id)->toBe($user->id);
})->with('crmActivityOwners');

it('CrmLunchesRelationManager creates a Lunch attached to the new owner family', function (string $modelClass, array $extra) {
    $user = crmCrossOwnerLoginUser();
    $owner = crmCrossOwnerMake($modelClass, $extra);

    $rm = crmCrossOwnerLunchesRm();
    $rm->ownerRecord = $owner->fresh();
    $rm->data = [
        'name' => 'Cross-owner lunch ' . $modelClass,
        'description' => 'desc',
        'start_at' => now()->setSecond(0),
        'finish_at' => null,
        'location' => 'Cafe',
    ];
    $rm->createLunch();

    $lunch = Lunch::query()->where('name', 'Cross-owner lunch ' . $modelClass)->first();
    expect($lunch)->not->toBeNull();
    expect($lunch->lunchable_type)->toBe($owner->getMorphClass());
    expect((int) $lunch->lunchable_id)->toBe((int) $owner->id);
    expect((int) $lunch->user_owner_id)->toBe($user->id);
})->with('crmActivityOwners');

it('CrmFilesRelationManager creates a File attached to the new owner family', function (string $modelClass, array $extra) {
    $user = crmCrossOwnerLoginUser();
    $owner = crmCrossOwnerMake($modelClass, $extra);

    Storage::fake('local');
    Storage::fake(FileUploadConfiguration::disk());

    $rm = crmCrossOwnerFilesRm();
    $rm->ownerRecord = $owner->fresh();
    $rm->data = ['file' => crmTemporaryUpload(UploadedFile::fake()->create('cross-owner.pdf', 12, 'application/pdf'))];
    $rm->createFile();

    $file = File::query()->where('name', 'cross-owner.pdf')->first();
    expect($file)->not->toBeNull();
    expect($file->fileable_type)->toBe($owner->getMorphClass());
    expect((int) $file->fileable_id)->toBe((int) $owner->id);
    expect((int) $file->user_created_id)->toBe($user->id);

    // Core's store() names the directory after the owner, so a Deal's files
    // no longer land under laravel-crm/lead/.
    $directory = 'laravel-crm/' . strtolower(class_basename($owner)) . '/' . $owner->id . '/files/';
    expect($file->file)->toStartWith($directory)
        ->and($file->file)->not->toContain('/lead/')
        ->and($file->disk)->toBe('local');
    Storage::disk('local')->assertExists($file->file);
})->with('crmActivityOwners');
