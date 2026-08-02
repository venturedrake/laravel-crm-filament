<?php

namespace VentureDrake\LaravelCrmFilament\RelationManagers;

use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrmFilament\Concerns\RollsUpRelatedActivity;

class CrmMeetingsRelationManager extends MeetingsRelationManager
{
    use RollsUpRelatedActivity;

    protected string $view = 'laravel-crm-filament::crm-meetings';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public ?int $editingId = null;

    public function mount(): void
    {
        parent::mount();

        $this->form->fill($this->defaultFormData());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Forms\Components\TextInput::make('name')
                    ->label(__('laravel-crm-filament::labels.fields.subject'))
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Grid::make(2)->schema([
                    Forms\Components\DateTimePicker::make('start_at')
                        ->label(__('laravel-crm-filament::labels.money.start_at')),
                    Forms\Components\DateTimePicker::make('finish_at')
                        ->label(__('laravel-crm-filament::labels.money.finish_at')),
                ]),
                Forms\Components\Select::make('guests')
                    ->label(__('laravel-crm-filament::labels.fields.guests'))
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->placeholder('Search ...')
                    ->options(fn () => Person::query()->orderBy('first_name')->get()->pluck('name', 'id')->all())
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('location')
                    ->label(__('laravel-crm-filament::labels.fields.location'))
                    ->maxLength(255)
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('description')
                    ->label(__('laravel-crm-filament::labels.fields.description'))
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }

    public function createMeeting(): void
    {
        $data = $this->form->getState();

        $meeting = $this->getOwnerRecord()->meetings()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'start_at' => $data['start_at'] ?? null,
            'finish_at' => $data['finish_at'] ?? null,
            'location' => $data['location'] ?? null,
            'user_owner_id' => auth()->id(),
            'user_created_id' => auth()->id(),
        ]);

        $this->syncGuests($meeting, $data['guests'] ?? []);

        static::logCrmActivity($this->getOwnerRecord(), $meeting);

        $this->form->fill($this->defaultFormData());

        Notification::make()
            ->title('Meeting added')
            ->success()
            ->send();
    }

    public function editMeeting(int $id): void
    {
        $meeting = $this->getOwnerRecord()->meetings()->whereKey($id)->first();

        if ($meeting === null) {
            return;
        }

        $this->editingId = (int) $meeting->id;

        $this->form->fill([
            'name' => $meeting->name,
            'description' => $meeting->description,
            'start_at' => $meeting->start_at,
            'finish_at' => $meeting->finish_at,
            'location' => $meeting->location ?? null,
            'guests' => $meeting->contacts()
                ->where('entityable_type', Person::class)
                ->pluck('entityable_id')
                ->map(fn ($id) => (int) $id)
                ->all(),
        ]);
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;

        $this->form->fill($this->defaultFormData());
    }

    public function updateMeeting(): void
    {
        if ($this->editingId === null) {
            return;
        }

        $data = $this->form->getState();

        $meeting = $this->getOwnerRecord()->meetings()->whereKey($this->editingId)->first();

        if ($meeting === null) {
            $this->cancelEdit();

            return;
        }

        $meeting->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'start_at' => $data['start_at'] ?? null,
            'finish_at' => $data['finish_at'] ?? null,
            'location' => $data['location'] ?? null,
            'user_updated_id' => auth()->id(),
        ]);

        $this->syncGuests($meeting, $data['guests'] ?? []);

        static::logCrmActivity($this->getOwnerRecord(), $meeting);

        $this->editingId = null;

        $this->form->fill($this->defaultFormData());

        Notification::make()
            ->title('Meeting updated')
            ->success()
            ->send();
    }

    public function deleteMeeting(int $id): void
    {
        $meeting = $this->getOwnerRecord()->meetings()->whereKey($id)->first();

        if ($meeting === null) {
            return;
        }

        $meeting->delete();

        if ($this->editingId === (int) $id) {
            $this->editingId = null;
            $this->form->fill($this->defaultFormData());
        }

        Notification::make()
            ->title('Meeting deleted')
            ->success()
            ->send();
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultFormData(): array
    {
        return [
            'name' => null,
            'description' => null,
            'start_at' => now(),
            'finish_at' => null,
            'guests' => [],
            'location' => null,
        ];
    }

    /**
     * Sync the Meeting's guest contacts to match the supplied Person ids.
     *
     * @param  array<int|string>  $personIds
     */
    protected function syncGuests($meeting, array $personIds): void
    {
        $personIds = array_values(array_filter(array_map('intval', $personIds)));

        $meeting->contacts()
            ->where('entityable_type', Person::class)
            ->delete();

        foreach ($personIds as $pid) {
            $person = Person::find($pid);
            if ($person === null) {
                continue;
            }
            $meeting->contacts()->create([
                'entityable_type' => $person->getMorphClass(),
                'entityable_id' => $person->id,
                'user_created_id' => auth()->id(),
            ]);
        }
    }
}
