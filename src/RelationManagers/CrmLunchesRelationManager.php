<?php

namespace VentureDrake\LaravelCrmFilament\RelationManagers;

use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrmFilament\Concerns\LogsCrmActivity;
use VentureDrake\LaravelCrmFilament\Concerns\RollsUpRelatedActivity;
use VentureDrake\LaravelCrmFilament\Support\EncryptedContactSearch;

class CrmLunchesRelationManager extends LunchesRelationManager
{
    use LogsCrmActivity;
    use RollsUpRelatedActivity;

    protected string $view = 'laravel-crm-filament::crm-lunches';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public ?int $editingId = null;

    public function isReadOnly(): bool
    {
        return false;
    }

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
                    // Both times required and the finish not before the
                    // start, as core 2.7.0 checks (`after_or_equal:start_at`).
                    Forms\Components\DateTimePicker::make('start_at')
                        ->label(__('laravel-crm-filament::labels.money.start_at'))
                        ->required(),
                    Forms\Components\DateTimePicker::make('finish_at')
                        ->label(__('laravel-crm-filament::labels.money.finish_at'))
                        ->required()
                        ->afterOrEqual('start_at'),
                ]),
                // Searched on the server as the user types rather than
                // preloading every person, as core 2.7.0's guest picker does.
                Forms\Components\Select::make('guests')
                    ->label(__('laravel-crm-filament::labels.fields.guests'))
                    ->multiple()
                    ->tap(fn (Forms\Components\Select $select) => EncryptedContactSearch::personSearchSelect($select))
                    ->placeholder('Search ...')
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

    public function createLunch(): void
    {
        $data = $this->form->getState();

        $lunch = $this->getOwnerRecord()->lunches()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'start_at' => $data['start_at'] ?? null,
            'finish_at' => $data['finish_at'] ?? null,
            'location' => $data['location'] ?? null,
            'user_owner_id' => auth()->id(),
            'user_created_id' => auth()->id(),
        ]);

        $this->syncGuests($lunch, $data['guests'] ?? []);

        static::logCrmActivity($this->getOwnerRecord(), $lunch);

        $this->form->fill($this->defaultFormData());

        Notification::make()
            ->title('Lunch added')
            ->success()
            ->send();
    }

    public function editLunch(int $id): void
    {
        $lunch = $this->getOwnerRecord()->lunches()->whereKey($id)->first();

        if ($lunch === null) {
            return;
        }

        $this->editingId = (int) $lunch->id;

        $this->form->fill([
            'name' => $lunch->name,
            'description' => $lunch->description,
            'start_at' => $lunch->start_at,
            'finish_at' => $lunch->finish_at,
            'location' => $lunch->location ?? null,
            'guests' => $lunch->contacts()
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

    public function updateLunch(): void
    {
        if ($this->editingId === null) {
            return;
        }

        $data = $this->form->getState();

        $lunch = $this->getOwnerRecord()->lunches()->whereKey($this->editingId)->first();

        if ($lunch === null) {
            $this->cancelEdit();

            return;
        }

        $lunch->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'start_at' => $data['start_at'] ?? null,
            'finish_at' => $data['finish_at'] ?? null,
            'location' => $data['location'] ?? null,
            'user_updated_id' => auth()->id(),
        ]);

        $this->syncGuests($lunch, $data['guests'] ?? []);

        static::logCrmActivity($this->getOwnerRecord(), $lunch);

        $this->editingId = null;

        $this->form->fill($this->defaultFormData());

        Notification::make()
            ->title('Lunch updated')
            ->success()
            ->send();
    }

    public function deleteLunch(int $id): void
    {
        $lunch = $this->getOwnerRecord()->lunches()->whereKey($id)->first();

        if ($lunch === null) {
            return;
        }

        $lunch->delete();

        if ($this->editingId === (int) $id) {
            $this->editingId = null;
            $this->form->fill($this->defaultFormData());
        }

        Notification::make()
            ->title('Lunch deleted')
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
     * Sync the Lunch's guest contacts to match the supplied Person ids.
     *
     * @param  array<int|string>  $personIds
     */
    protected function syncGuests($lunch, array $personIds): void
    {
        $personIds = array_values(array_filter(array_map('intval', $personIds)));

        $lunch->contacts()
            ->where('entityable_type', Person::class)
            ->delete();

        foreach ($personIds as $pid) {
            $person = Person::find($pid);
            if ($person === null) {
                continue;
            }
            $lunch->contacts()->create([
                'entityable_type' => $person->getMorphClass(),
                'entityable_id' => $person->id,
                'user_created_id' => auth()->id(),
            ]);
        }
    }
}
