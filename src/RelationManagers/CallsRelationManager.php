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

class CallsRelationManager extends RelationManager
{
    use LogsCrmActivity;

    protected static string $relationship = 'calls';

    protected static ?string $title = 'Calls';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(255),
            Forms\Components\Textarea::make('description')
                ->rows(2)
                ->columnSpanFull(),
            Grid::make(2)->schema([
                // Both times required and the finish not before the start,
                // as core 2.7.0 checks (`after_or_equal:start_at`).
                Forms\Components\DateTimePicker::make('start_at')
                    ->label(__('laravel-crm-filament::labels.money.start'))
                    ->required(),
                Forms\Components\DateTimePicker::make('finish_at')
                    ->label(__('laravel-crm-filament::labels.money.finish'))
                    ->required()
                    ->afterOrEqual('start_at'),
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
                Tables\Columns\TextColumn::make('name')->limit(60)->wrap(),
                Tables\Columns\TextColumn::make('start_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('finish_at')->dateTime()->toggleable(),
                Tables\Columns\TextColumn::make('ownerUser.name')
                    ->label(__('laravel-crm-filament::labels.fields.owner'))
                    ->toggleable(),
            ])
            ->defaultSort('start_at', 'desc')
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
