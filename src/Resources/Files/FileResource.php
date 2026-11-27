<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Files;

use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use VentureDrake\LaravelCrm\Models\File;
use VentureDrake\LaravelCrmFilament\Concerns\StandaloneActivityResource;
use VentureDrake\LaravelCrmFilament\LaravelCrmPlugin;
use VentureDrake\LaravelCrmFilament\Resources\Files\Pages\ListFiles;
use VentureDrake\LaravelCrmFilament\Resources\Files\Pages\ViewFile;
use VentureDrake\LaravelCrmFilament\Support\FileDownload;
use VentureDrake\LaravelCrmFilament\Support\ParentTypeOptions;

class FileResource extends Resource
{
    use StandaloneActivityResource;

    protected static ?string $model = File::class;

    protected static ?string $slug = 'files';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-paper-clip';

    protected static ?int $navigationSort = 74;

    public static function getNavigationGroup(): ?string
    {
        return LaravelCrmPlugin::get()->getNavigationGroup() ?? 'Activity';
    }

    public static function getRecordRouteKeyName(): ?string
    {
        return 'external_id';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('name')->disabled(),
            Forms\Components\TextInput::make('title')->label(__('laravel-crm-filament::labels.fields.description'))->disabled(),
            Forms\Components\TextInput::make('format')->label(__('laravel-crm-filament::labels.file.file_type'))->disabled(),
            Forms\Components\TextInput::make('fileable_type')->label(__('laravel-crm-filament::labels.fields.parent_type'))->disabled(),
            Forms\Components\TextInput::make('mime')->disabled(),
            Forms\Components\TextInput::make('filesize')->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->limit(60),
                Tables\Columns\TextColumn::make('title')->label(__('laravel-crm-filament::labels.fields.description'))->limit(50)->toggleable(),
                Tables\Columns\TextColumn::make('format')->label(__('laravel-crm-filament::labels.fields.type'))->badge()->toggleable(),
                Tables\Columns\TextColumn::make('fileable_type')
                    ->label(__('laravel-crm-filament::labels.fields.parent'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? (ParentTypeOptions::all()[$state] ?? class_basename($state)) : '—'),
                Tables\Columns\TextColumn::make('createdByUser.name')->label(__('laravel-crm-filament::labels.fields.owner'))->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('fileable_type')
                    ->label(__('laravel-crm-filament::labels.fields.parent_type'))
                    ->options(ParentTypeOptions::all()),
                Tables\Filters\SelectFilter::make('user_created_id')
                    ->label(__('laravel-crm-filament::labels.fields.owner'))
                    ->relationship('createdByUser', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\Filter::make('created_at')
                    ->schema([
                        Forms\Components\DatePicker::make('from'),
                        Forms\Components\DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                            ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d));
                    }),
            ])
            ->recordActions([
                Actions\ViewAction::make()
                    ->button()
                    ->hiddenLabel(),
                Actions\Action::make('download')
                    ->label(__('laravel-crm-filament::labels.actions.download'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (File $record): ?string => static::buildDownloadUrl($record))
                    ->openUrlInNewTab()
                    ->visible(fn (File $record): bool => static::buildDownloadUrl($record) !== null),
                static::openParentAction('fileable_type', 'fileable_id'),
            ]);
    }

    public static function buildDownloadUrl(File $record): ?string
    {
        if (! $record->file || ! FileDownload::disk($record)->exists($record->file)) {
            return null;
        }

        return FileDownload::urlFor($record);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFiles::route('/'),
            'view' => ViewFile::route('/{record}'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
