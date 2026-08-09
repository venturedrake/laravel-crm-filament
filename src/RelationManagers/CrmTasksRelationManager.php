<?php

namespace VentureDrake\LaravelCrmFilament\RelationManagers;

use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use VentureDrake\LaravelCrmFilament\Concerns\RollsUpRelatedActivity;

class CrmTasksRelationManager extends TasksRelationManager
{
    use RollsUpRelatedActivity;

    protected string $view = 'laravel-crm-filament::crm-tasks';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public ?int $editingId = null;

    public function mount(): void
    {
        parent::mount();

        $this->form->fill([
            'name' => null,
            'description' => null,
            'start_at' => null,
            'due_at' => now(),
            'user_owner_id' => auth()->id(),
            'user_assigned_id' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Forms\Components\TextInput::make('name')
                    ->label(__('laravel-crm-filament::labels.fields.task'))
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('description')
                    ->label(__('laravel-crm-filament::labels.fields.further_details'))
                    ->rows(3)
                    ->columnSpanFull(),
                // core's TaskService writes start_at unconditionally, so this
                // field has to exist or every save through here clears it.
                Forms\Components\DateTimePicker::make('start_at')
                    ->label(__('laravel-crm-filament::labels.fields.when_does_it_start'))
                    ->seconds(false)
                    ->minutesStep(15),
                Forms\Components\DateTimePicker::make('due_at')
                    ->label(__('laravel-crm-filament::labels.fields.whens_it_due')),
                Grid::make(2)->schema([
                    Forms\Components\Select::make('user_owner_id')
                        ->label(__('laravel-crm-filament::labels.fields.who_requested_the_task'))
                        ->relationship('ownerUser', 'name')
                        ->searchable()
                        ->preload(),
                    Forms\Components\Select::make('user_assigned_id')
                        ->label(__('laravel-crm-filament::labels.fields.who_is_responsible'))
                        ->relationship('assignedToUser', 'name')
                        ->searchable()
                        ->preload(),
                ]),
            ]);
    }

    public function createTask(): void
    {
        $data = $this->form->getState();

        $task = $this->getOwnerRecord()->tasks()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'start_at' => $data['start_at'] ?? null,
            'due_at' => $data['due_at'] ?? null,
            'user_owner_id' => $data['user_owner_id'] ?? auth()->id(),
            'user_assigned_id' => $data['user_assigned_id'] ?? null,
            'user_created_id' => auth()->id(),
        ]);

        static::logCrmActivity($this->getOwnerRecord(), $task);

        $this->form->fill([
            'name' => null,
            'description' => null,
            'start_at' => null,
            'due_at' => now(),
            'user_owner_id' => auth()->id(),
            'user_assigned_id' => null,
        ]);

        Notification::make()
            ->title('Task added')
            ->success()
            ->send();
    }

    public function editTask(int $id): void
    {
        $task = $this->getOwnerRecord()->tasks()->whereKey($id)->first();

        if ($task === null) {
            return;
        }

        $this->editingId = (int) $task->id;

        $this->form->fill([
            'name' => $task->name,
            'description' => $task->description,
            'start_at' => $task->start_at,
            'due_at' => $task->due_at,
            'user_owner_id' => $task->user_owner_id,
            'user_assigned_id' => $task->user_assigned_id,
        ]);
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;

        $this->form->fill([
            'name' => null,
            'description' => null,
            'start_at' => null,
            'due_at' => now(),
            'user_owner_id' => auth()->id(),
            'user_assigned_id' => null,
        ]);
    }

    public function updateTask(): void
    {
        if ($this->editingId === null) {
            return;
        }

        $data = $this->form->getState();

        $task = $this->getOwnerRecord()->tasks()->whereKey($this->editingId)->first();

        if ($task === null) {
            $this->cancelEdit();

            return;
        }

        $task->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'start_at' => $data['start_at'] ?? null,
            'due_at' => $data['due_at'] ?? null,
            'user_owner_id' => $data['user_owner_id'] ?? null,
            'user_assigned_id' => $data['user_assigned_id'] ?? null,
            'user_updated_id' => auth()->id(),
        ]);

        static::logCrmActivity($this->getOwnerRecord(), $task);

        $this->editingId = null;

        $this->form->fill([
            'name' => null,
            'description' => null,
            'start_at' => null,
            'due_at' => now(),
            'user_owner_id' => auth()->id(),
            'user_assigned_id' => null,
        ]);

        Notification::make()
            ->title('Task updated')
            ->success()
            ->send();
    }

    public function completeTask(int $id): void
    {
        $task = $this->getOwnerRecord()->tasks()->whereKey($id)->first();

        if ($task === null) {
            return;
        }

        $task->update([
            'completed_at' => now(),
            'user_updated_id' => auth()->id(),
        ]);

        static::logCrmActivity($this->getOwnerRecord(), $task);

        Notification::make()
            ->title('Task completed')
            ->success()
            ->send();
    }

    public function deleteTask(int $id): void
    {
        $task = $this->getOwnerRecord()->tasks()->whereKey($id)->first();

        if ($task === null) {
            return;
        }

        $task->delete();

        if ($this->editingId === (int) $id) {
            $this->editingId = null;
            $this->form->fill([
                'name' => null,
                'description' => null,
                'due_at' => now(),
                'user_owner_id' => auth()->id(),
                'user_assigned_id' => null,
            ]);
        }

        Notification::make()
            ->title('Task deleted')
            ->success()
            ->send();
    }
}
