<?php

namespace VentureDrake\LaravelCrmFilament\RelationManagers;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrmFilament\Concerns\LogsCrmActivity;

class TasksRelationManager extends RelationManager
{
    use LogsCrmActivity;

    protected static string $relationship = 'tasks';

    protected static ?string $title = 'Tasks';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(255),
            Forms\Components\Textarea::make('description')
                ->rows(2)
                ->columnSpanFull(),
            Grid::make(3)->schema([
                // Omitting start_at here would clear it on every edit — core's
                // TaskService::update() writes $request->start_at unconditionally.
                Forms\Components\DateTimePicker::make('start_at')
                    ->label(__('laravel-crm-filament::labels.money.start_at'))
                    ->seconds(false)
                    ->minutesStep(15),
                Forms\Components\DateTimePicker::make('due_at')->label(__('laravel-crm-filament::labels.money.due')),
                Forms\Components\DateTimePicker::make('completed_at')->label(__('laravel-crm-filament::labels.money.completed')),
            ]),
            Grid::make(2)->schema([
                Forms\Components\Select::make('user_owner_id')
                    ->label(__('laravel-crm-filament::labels.fields.owner'))
                    ->relationship('ownerUser', 'name')
                    ->searchable()
                    ->preload(),
                Forms\Components\Select::make('user_assigned_id')
                    ->label(__('laravel-crm-filament::labels.fields.assigned_to'))
                    ->relationship('assignedToUser', 'name')
                    ->searchable()
                    ->preload(),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\IconColumn::make('completed_at')
                    ->label(__('laravel-crm-filament::labels.money.done'))
                    ->boolean()
                    ->getStateUsing(fn ($record) => filled($record->completed_at)),
                Tables\Columns\TextColumn::make('name')->limit(60)->wrap(),
                Tables\Columns\TextColumn::make('due_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('assignedToUser.name')
                    ->label(__('laravel-crm-filament::labels.fields.assignee'))
                    ->toggleable(),
            ])
            ->defaultSort('due_at')
            ->headerActions([
                Actions\CreateAction::make()
                    ->after(fn (Model $record, RelationManager $livewire) => static::logCrmActivity($livewire->getOwnerRecord(), $record)),
            ])
            ->recordActions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ]);
    }
}
