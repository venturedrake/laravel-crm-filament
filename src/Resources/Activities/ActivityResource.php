<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Activities;

use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use VentureDrake\LaravelCrm\Models\Activity;
use VentureDrake\LaravelCrm\Models\Call;
use VentureDrake\LaravelCrm\Models\File;
use VentureDrake\LaravelCrm\Models\Lunch;
use VentureDrake\LaravelCrm\Models\Meeting;
use VentureDrake\LaravelCrm\Models\Note;
use VentureDrake\LaravelCrm\Models\Task;
use VentureDrake\LaravelCrmFilament\Concerns\GuardsPoliciedResource;
use VentureDrake\LaravelCrmFilament\Concerns\StandaloneActivityResource;
use VentureDrake\LaravelCrmFilament\LaravelCrmPlugin;
use VentureDrake\LaravelCrmFilament\Resources\Activities\Pages\ListActivities;
use VentureDrake\LaravelCrmFilament\Resources\Activities\Pages\ViewActivity;
use VentureDrake\LaravelCrmFilament\Support\ParentTypeOptions;

class ActivityResource extends Resource
{
    use GuardsPoliciedResource;
    use StandaloneActivityResource;

    protected static ?string $model = Activity::class;

    protected static ?string $slug = 'activity-log';

    protected static ?string $recordTitleAttribute = 'description';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-bolt';

    protected static ?int $navigationSort = 75;

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
            Forms\Components\TextInput::make('description')->disabled(),
            Forms\Components\TextInput::make('event')->disabled(),
            Forms\Components\TextInput::make('timelineable_type')->label(__('laravel-crm-filament::labels.fields.parent_type'))->disabled(),
            Forms\Components\TextInput::make('recordable_type')->label(__('laravel-crm-filament::labels.fields.record_type'))->disabled(),
            Forms\Components\TextInput::make('log_name')->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('description')->limit(60)->toggleable(),
                Tables\Columns\TextColumn::make('event')->badge()->toggleable(),
                Tables\Columns\TextColumn::make('recordable_type')
                    ->label(__('laravel-crm-filament::labels.fields.record'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('timelineable_type')
                    ->label(__('laravel-crm-filament::labels.fields.parent'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? (ParentTypeOptions::all()[$state] ?? class_basename($state)) : '—'),
                Tables\Columns\TextColumn::make('causeable_id')->label(__('laravel-crm-filament::labels.fields.by_user'))->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('timelineable_type')
                    ->label(__('laravel-crm-filament::labels.fields.parent_type'))
                    ->options(ParentTypeOptions::all()),
                Tables\Filters\SelectFilter::make('recordable_type')
                    ->label(__('laravel-crm-filament::labels.fields.record_type'))
                    ->options([
                        Note::class => 'Note',
                        Task::class => 'Task',
                        Call::class => 'Call',
                        Meeting::class => 'Meeting',
                        Lunch::class => 'Lunch',
                        File::class => 'File',
                    ]),
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
                static::openParentAction('timelineable_type', 'timelineable_id'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivities::route('/'),
            'view' => ViewActivity::route('/{record}'),
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
