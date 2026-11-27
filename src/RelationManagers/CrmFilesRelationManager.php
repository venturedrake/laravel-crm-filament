<?php

namespace VentureDrake\LaravelCrmFilament\RelationManagers;

use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use VentureDrake\LaravelCrm\Models\File;
use VentureDrake\LaravelCrmFilament\Concerns\RollsUpRelatedActivity;
use VentureDrake\LaravelCrmFilament\Support\AttachmentStore;
use VentureDrake\LaravelCrmFilament\Support\FileDownload;

class CrmFilesRelationManager extends FilesRelationManager
{
    use RollsUpRelatedActivity;

    protected string $view = 'laravel-crm-filament::crm-files';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        parent::mount();

        $this->form->fill([
            'file' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                static::attachmentUploadField()
                    ->hiddenLabel()
                    ->columnSpanFull(),
            ]);
    }

    public function createFile(): void
    {
        $data = $this->form->getState();

        $this->attachUpload($data['file'] ?? null);
    }

    /**
     * Store an upload against the owner record and reset the card.
     *
     * Split from {@see self::createFile()} so the store-and-log path is the
     * same code whether the state came from the mounted form or a test.
     */
    protected function attachUpload(mixed $upload): ?File
    {
        if (blank($upload)) {
            return null;
        }

        $file = AttachmentStore::storeFor($this->getOwnerRecord(), $upload, [], 'data.file');

        static::logCrmActivity($this->getOwnerRecord(), $file);

        $this->resetUploadForm();

        Notification::make()
            ->title('File added')
            ->success()
            ->send();

        return $file;
    }

    protected function resetUploadForm(): void
    {
        $this->form->fill([
            'file' => null,
        ]);
    }

    /**
     * Read-only, so it resolves across the rolled-up set: a file rolled up
     * from a related contact still renders a working download link.
     */
    public function downloadFile(int $id): ?string
    {
        $file = $this->findRolledUpActivityRecord($id);

        if (! $file instanceof File) {
            return null;
        }

        return static::buildOwnerDownloadUrl($file);
    }

    public function deleteFile(int $id): void
    {
        $file = $this->getOwnerRecord()->files()->whereKey($id)->first();

        if ($file === null) {
            return;
        }

        $file->delete();

        Notification::make()
            ->title('File deleted')
            ->success()
            ->send();
    }

    protected static function buildOwnerDownloadUrl(File $file): ?string
    {
        return FileDownload::urlFor($file);
    }
}
