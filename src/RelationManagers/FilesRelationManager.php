<?php

namespace VentureDrake\LaravelCrmFilament\RelationManagers;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrm\Models\File;
use VentureDrake\LaravelCrm\Support\AttachmentUpload;
use VentureDrake\LaravelCrmFilament\Concerns\LogsCrmActivity;
use VentureDrake\LaravelCrmFilament\Support\AttachmentStore;
use VentureDrake\LaravelCrmFilament\Support\FileDownload;

class FilesRelationManager extends RelationManager
{
    use LogsCrmActivity;

    protected static string $relationship = 'files';

    protected static ?string $title = 'Files';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            static::attachmentUploadField()
                ->label(__('laravel-crm-filament::labels.file.file'))
                ->columnSpanFull(),
            Forms\Components\TextInput::make('title')
                ->label(__('laravel-crm-filament::labels.fields.description'))
                ->maxLength(255),
            Forms\Components\TextInput::make('format')
                ->label(__('laravel-crm-filament::labels.file.file_type'))
                ->maxLength(255)
                ->helperText('Optional category, e.g. contract, invoice, photo.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->limit(60)->wrap(),
                Tables\Columns\TextColumn::make('title')->label(__('laravel-crm-filament::labels.fields.description'))->limit(40)->toggleable(),
                Tables\Columns\TextColumn::make('format')->label(__('laravel-crm-filament::labels.fields.type'))->badge()->toggleable(),
                Tables\Columns\TextColumn::make('mime')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('createdByUser.name')
                    ->label(__('laravel-crm-filament::labels.file.uploaded_by'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                Actions\CreateAction::make()
                    ->using(fn (array $data, RelationManager $livewire, Actions\CreateAction $action): Model => AttachmentStore::storeFor(
                        $livewire->getOwnerRecord(),
                        $data['file'] ?? null,
                        [
                            'title' => $data['title'] ?? null,
                            'format' => $data['format'] ?? null,
                        ],
                        // The modal's form lives at mountedActions.{n}.data, so
                        // that is where a rejected upload has to be reported.
                        'mountedActions.' . ($action->getNestingIndex() ?? 0) . '.data.file',
                    ))
                    ->after(fn (Model $record, RelationManager $livewire) => static::logCrmActivity($livewire->getOwnerRecord(), $record)),
            ])
            ->recordActions([
                Actions\Action::make('download')
                    ->label(__('laravel-crm-filament::labels.actions.download'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (File $record): ?string => static::buildDownloadUrl($record))
                    ->openUrlInNewTab()
                    ->visible(fn (File $record): bool => static::buildDownloadUrl($record) !== null),
                Actions\DeleteAction::make(),
            ]);
    }

    /**
     * The upload field both files managers share.
     *
     * Filament does not store the file: the state stays a Livewire temporary
     * upload and {@see AttachmentStore::storeFor()} hands it to core, which
     * checks it and writes it to the private attachments disk under a hashed
     * name. The type and size limits here are hints for the browser; the
     * server-side check is core's.
     */
    protected static function attachmentUploadField(): Forms\Components\FileUpload
    {
        return Forms\Components\FileUpload::make('file')
            ->storeFiles(false)
            ->required()
            ->maxSize(AttachmentUpload::MAX_SIZE_KB)
            ->acceptedFileTypes(AttachmentStore::acceptedMimeTypes())
            // Filament's own message lists every MIME alias; name the
            // extensions a user actually recognises instead.
            ->validationMessages([
                'mimetypes' => __('validation.mimes', ['values' => implode(', ', AttachmentUpload::EXTENSIONS)]),
            ]);
    }

    protected static function buildDownloadUrl(File $record): ?string
    {
        if (! $record->file || ! FileDownload::disk($record)->exists($record->file)) {
            return null;
        }

        return FileDownload::urlFor($record);
    }
}
