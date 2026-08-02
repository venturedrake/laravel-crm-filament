<?php

namespace VentureDrake\LaravelCrmFilament\RelationManagers;

use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use VentureDrake\LaravelCrmFilament\Concerns\RollsUpRelatedActivity;

class CrmNotesRelationManager extends NotesRelationManager
{
    use RollsUpRelatedActivity;

    protected string $view = 'laravel-crm-filament::crm-notes';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public ?int $editingId = null;

    public function mount(): void
    {
        parent::mount();

        $this->form->fill([
            'content' => null,
            'noted_at' => now(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Forms\Components\Textarea::make('content')
                    ->required()
                    ->rows(4)
                    ->columnSpanFull(),
                Forms\Components\DateTimePicker::make('noted_at'),
            ]);
    }

    public function createNote(): void
    {
        $data = $this->form->getState();

        $note = $this->getOwnerRecord()->notes()->create([
            'content' => $data['content'],
            'noted_at' => $data['noted_at'] ?? null,
            'user_created_id' => auth()->id(),
        ]);

        static::logCrmActivity($this->getOwnerRecord(), $note);

        $this->form->fill([
            'content' => null,
            'noted_at' => now(),
        ]);

        Notification::make()
            ->title('Note added')
            ->success()
            ->send();
    }

    public function editNote(int $id): void
    {
        $note = $this->getOwnerRecord()->notes()->whereKey($id)->first();

        if ($note === null) {
            return;
        }

        $this->editingId = (int) $note->id;

        $this->form->fill([
            'content' => $note->content,
            'noted_at' => $note->noted_at,
        ]);
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;

        $this->form->fill([
            'content' => null,
            'noted_at' => now(),
        ]);
    }

    public function updateNote(): void
    {
        if ($this->editingId === null) {
            return;
        }

        $data = $this->form->getState();

        $note = $this->getOwnerRecord()->notes()->whereKey($this->editingId)->first();

        if ($note === null) {
            $this->cancelEdit();

            return;
        }

        $note->update([
            'content' => $data['content'],
            'noted_at' => $data['noted_at'] ?? null,
            'user_updated_id' => auth()->id(),
        ]);

        static::logCrmActivity($this->getOwnerRecord(), $note);

        $this->editingId = null;

        $this->form->fill([
            'content' => null,
            'noted_at' => now(),
        ]);

        Notification::make()
            ->title('Note updated')
            ->success()
            ->send();
    }

    public function deleteNote(int $id): void
    {
        $note = $this->getOwnerRecord()->notes()->whereKey($id)->first();

        if ($note === null) {
            return;
        }

        $note->delete();

        if ($this->editingId === (int) $id) {
            $this->editingId = null;
            $this->form->fill([
                'content' => null,
                'noted_at' => now(),
            ]);
        }

        Notification::make()
            ->title('Note deleted')
            ->success()
            ->send();
    }
}
