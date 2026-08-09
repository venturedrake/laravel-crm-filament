<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Tasks;

use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use VentureDrake\LaravelCrm\Models\Task;
use VentureDrake\LaravelCrmFilament\Concerns\ExportsCsv;
use VentureDrake\LaravelCrmFilament\Concerns\HasCrmCustomFieldEntries;
use VentureDrake\LaravelCrmFilament\Concerns\HasCrmCustomFields;
use VentureDrake\LaravelCrmFilament\Concerns\UsesExternalIdRouting;
use VentureDrake\LaravelCrmFilament\LaravelCrmPlugin;
use VentureDrake\LaravelCrmFilament\Resources\Tasks\Pages\CreateTask;
use VentureDrake\LaravelCrmFilament\Resources\Tasks\Pages\EditTask;
use VentureDrake\LaravelCrmFilament\Resources\Tasks\Pages\ListTasks;
use VentureDrake\LaravelCrmFilament\Resources\Tasks\Pages\TaskKanban;
use VentureDrake\LaravelCrmFilament\Resources\Tasks\Pages\ViewTask;

class TaskResource extends Resource
{
    use HasCrmCustomFieldEntries;
    use HasCrmCustomFields;
    use UsesExternalIdRouting;

    protected static ?string $model = Task::class;

    protected static ?string $slug = 'tasks';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-check-circle';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return LaravelCrmPlugin::get()->getNavigationGroup() ?? 'Activity';
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Task::query()->whereNull('completed_at')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        $components = [
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(255),

            Forms\Components\Textarea::make('description')
                ->rows(3)
                ->columnSpanFull(),

            // core's TaskService::create()/update() write 'start_at' from the
            // request unconditionally, so a form without this field silently
            // clears it on every save. See TaskStartAtTest.
            Forms\Components\DateTimePicker::make('start_at')
                ->label(__('laravel-crm-filament::labels.money.start_at'))
                ->seconds(false)
                ->minutesStep(15),

            Forms\Components\DateTimePicker::make('due_at')
                ->label(__('laravel-crm-filament::labels.money.due_at')),

            Forms\Components\Select::make('user_owner_id')
                ->label(__('laravel-crm-filament::labels.fields.created_by'))
                ->relationship('ownerUser', 'name')
                ->searchable()
                ->preload(),

            Forms\Components\Select::make('user_assigned_id')
                ->label(__('laravel-crm-filament::labels.fields.assigned_to'))
                ->relationship('assignedToUser', 'name')
                ->searchable()
                ->preload(),
        ];

        if ($customFields = static::crmCustomFieldsSection(Task::class)) {
            $components[] = $customFields;
        }

        return $schema->components($components)->columns(1);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('laravel-crm-filament::labels.sections.details'))
                ->schema(fn (?Task $record) => array_merge([
                    TextEntry::make('created_at')
                        ->label(__('laravel-crm-filament::labels.fields.created'))
                        ->since(),

                    TextEntry::make('name')
                        ->label(__('laravel-crm-filament::labels.fields.name')),

                    TextEntry::make('description')
                        ->label(__('laravel-crm-filament::labels.fields.description'))
                        ->columnSpanFull()
                        ->placeholder('—'),

                    TextEntry::make('status')
                        ->label(__('laravel-crm-filament::labels.fields.status'))
                        ->state(fn (?Task $r) => $r?->completed_at ? 'Completed' : 'Pending')
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            'Completed' => 'success',
                            'Pending' => 'warning',
                            default => 'gray',
                        }),

                    TextEntry::make('start_at')
                        ->label(__('laravel-crm-filament::labels.money.start_at'))
                        ->since()
                        ->tooltip(fn (?Task $r): ?string => optional($r?->start_at)->format('M d, Y H:i'))
                        ->placeholder('—'),

                    TextEntry::make('due_at')
                        ->label(__('laravel-crm-filament::labels.money.due_at'))
                        ->since()
                        ->tooltip(fn (?Task $r): ?string => optional($r?->due_at)->format('M d, Y H:i'))
                        ->placeholder('—'),

                    TextEntry::make('completed_at')
                        ->label(__('laravel-crm-filament::labels.fields.completed'))
                        ->since()
                        ->tooltip(fn (?Task $r): ?string => optional($r?->completed_at)->format('M d, Y H:i'))
                        ->placeholder('—')
                        ->visible(fn (?Task $r): bool => $r?->completed_at !== null),

                    TextEntry::make('ownerUser.name')
                        ->label(__('laravel-crm-filament::labels.fields.created_by'))
                        ->placeholder(__('laravel-crm-filament::labels.misc.unallocated')),

                    TextEntry::make('assignedToUser.name')
                        ->label(__('laravel-crm-filament::labels.fields.assigned_to'))
                        ->placeholder('—'),
                ], $record ? static::crmCustomFieldEntries($record, false) : [])),

            Section::make(__('laravel-crm-filament::labels.sections.custom_fields'))
                ->schema(fn (?Task $record) => $record ? static::crmCustomFieldEntries($record, true) : [])
                ->hidden(function ($record): bool {
                    if (! $record instanceof Task) {
                        return true;
                    }

                    return ! $record->fields()
                        ->whereHas('field', fn ($q) => $q->whereNotNull('field_group_id'))
                        ->exists();
                }),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('laravel-crm-filament::labels.fields.created'))
                    ->since()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('laravel-crm-filament::labels.fields.status'))
                    ->state(fn (Task $record): string => $record->completed_at ? 'Completed' : 'Pending')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Completed' => 'success',
                        'Pending' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('completed_at', $direction)),

                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->limit(60),

                Tables\Columns\TextColumn::make('description')
                    ->label(__('laravel-crm-filament::labels.fields.description'))
                    ->limit(60)
                    ->tooltip(fn (Task $record): ?string => $record->description)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('start_at')
                    ->label(__('laravel-crm-filament::labels.money.start_at'))
                    ->since()
                    ->tooltip(fn (Task $record): ?string => optional($record->start_at)->format('M d, Y H:i'))
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('due_at')
                    ->since()
                    ->tooltip(fn (Task $record): ?string => optional($record->due_at)->format('M d, Y H:i'))
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('ownerUser.name')
                    ->label(__('laravel-crm-filament::labels.fields.created_by'))
                    ->toggleable()
                    ->placeholder(__('laravel-crm-filament::labels.misc.unallocated')),

                Tables\Columns\TextColumn::make('assignedToUser.name')
                    ->label(__('laravel-crm-filament::labels.fields.assigned_to'))
                    ->toggleable()
                    ->placeholder('—'),
            ])
            ->defaultSort('due_at', 'asc')
            ->filters([
                Tables\Filters\SelectFilter::make('user_owner_id')
                    ->label(__('laravel-crm-filament::labels.fields.created_by'))
                    ->multiple()
                    ->relationship('ownerUser', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('user_assigned_id')
                    ->label(__('laravel-crm-filament::labels.fields.assigned_to'))
                    ->multiple()
                    ->relationship('assignedToUser', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('status')
                    ->label(__('laravel-crm-filament::labels.fields.status'))
                    ->options([
                        'pending' => 'Pending',
                        'completed' => 'Completed',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'pending' => $query->whereNull('completed_at'),
                            'completed' => $query->whereNotNull('completed_at'),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                static::completeAction()
                    ->button()
                    ->label(__('laravel-crm-filament::labels.actions.mark_complete')),
                Actions\ViewAction::make()
                    ->button()
                    ->hiddenLabel(),
                Actions\EditAction::make()
                    ->button()
                    ->hiddenLabel(),
                Actions\DeleteAction::make()
                    ->button()
                    ->hiddenLabel()
                    ->requiresConfirmation(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\BulkAction::make('markComplete')
                        ->label(__('laravel-crm-filament::labels.actions.mark_complete'))
                        ->icon('heroicon-o-check')
                        ->color('success')
                        ->action(function (Collection $records): void {
                            $updated = 0;
                            foreach ($records as $record) {
                                if (! $record->completed_at) {
                                    $record->update(['completed_at' => now()]);
                                    $updated++;
                                }
                            }
                            Notification::make()->title($updated . ' task(s) marked complete')->success()->send();
                        }),
                    ExportsCsv::action(
                        columns: [
                            'Name' => fn ($r) => $r->name,
                            'Description' => fn ($r) => $r->description,
                            'Status' => fn ($r) => $r->completed_at ? 'Completed' : 'Pending',
                            'Start' => fn ($r) => $r->start_at,
                            'Due' => fn ($r) => $r->due_at,
                            'Completed' => fn ($r) => $r->completed_at,
                            'Owner' => fn ($r) => optional($r->ownerUser)->name,
                            'Assigned to' => fn ($r) => optional($r->assignedToUser)->name,
                            'Created' => fn ($r) => $r->created_at,
                        ],
                        filename: 'tasks',
                    ),
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function completeAction(): Actions\Action
    {
        return Actions\Action::make('markComplete')
            ->label(__('laravel-crm-filament::labels.actions.mark_complete'))
            ->icon('heroicon-o-check')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (?Task $record): bool => $record !== null && $record->completed_at === null)
            ->action(function (Task $record): void {
                $record->update(['completed_at' => now()]);

                Notification::make()
                    ->title(__('laravel-crm-filament::labels.actions.mark_complete'))
                    ->success()
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTasks::route('/'),
            'kanban' => TaskKanban::route('/kanban'),
            'create' => CreateTask::route('/create'),
            'view' => ViewTask::route('/{record}'),
            'edit' => EditTask::route('/{record}/edit'),
        ];
    }
}
