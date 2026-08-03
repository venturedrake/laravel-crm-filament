<?php

namespace VentureDrake\LaravelCrmFilament\RelationManagers;

use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use VentureDrake\LaravelCrm\Models\File;
use VentureDrake\LaravelCrmFilament\Concerns\RollsUpRelatedActivity;

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
        $disk = config('laravel-crm.upload_disk', 'public');

        return $schema
            ->statePath('data')
            ->components([
                Forms\Components\FileUpload::make('file')
                    ->hiddenLabel()
                    ->disk($disk)
                    ->directory(fn (): string => 'laravel-crm/lead/' . $this->getOwnerRecord()->id . '/files')
                    ->required()
                    ->preserveFilenames()
                    ->columnSpanFull(),
            ]);
    }

    public function createFile(): void
    {
        $data = $this->form->getState();

        $disk = config('laravel-crm.upload_disk', 'public');
        $path = $data['file'] ?? null;

        if (! $path) {
            return;
        }

        $name = basename($path);
        $filesize = null;
        $mime = null;
        $format = null;

        $storage = Storage::disk($disk);
        if ($storage->exists($path)) {
            $filesize = $storage->size($path);
            $mime = $storage->mimeType($path);
            $format = pathinfo($path, PATHINFO_EXTENSION) ?: null;
        }

        $file = $this->getOwnerRecord()->files()->create([
            'file' => $path,
            'name' => $name,
            'format' => $format,
            'filesize' => $filesize,
            'mime' => $mime,
            'disk' => $disk,
            'user_created_id' => auth()->id(),
        ]);

        static::logCrmActivity($this->getOwnerRecord(), $file);

        $this->form->fill([
            'file' => null,
        ]);

        Notification::make()
            ->title('File added')
            ->success()
            ->send();
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
        if (! $file->file) {
            return null;
        }

        $disk = $file->disk ?: config('laravel-crm.upload_disk', 'public');
        $storage = Storage::disk($disk);

        try {
            return $storage->temporaryUrl($file->file, now()->addMinutes(5));
        } catch (\Throwable $e) {
            return $storage->url($file->file);
        }
    }
}
