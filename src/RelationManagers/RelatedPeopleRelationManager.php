<?php

namespace VentureDrake\LaravelCrmFilament\RelationManagers;

use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use VentureDrake\LaravelCrm\Models\Contact;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrmFilament\Concerns\AuthorizesRelatedContacts;
use VentureDrake\LaravelCrmFilament\Resources\People\PersonResource;

class RelatedPeopleRelationManager extends RelationManager
{
    use AuthorizesRelatedContacts;

    protected static string $relationship = 'relatedPeopleContacts';

    protected static ?string $title = 'Related people';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\Select::make('person_id')
                ->label(__('laravel-crm-filament::labels.fields.contact'))
                ->options(function () {
                    $ownerId = $this->getOwnerRecord()?->id;

                    return Person::query()
                        ->when($ownerId, fn ($q) => $q->where('id', '!=', $ownerId))
                        ->orderBy('first_name')
                        ->get()
                        ->mapWithKeys(fn (Person $person) => [
                            $person->id => trim(($person->first_name ?? '') . ' ' . ($person->last_name ?? '')),
                        ])
                        ->filter()
                        ->toArray();
                })
                ->searchable()
                ->preload()
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('related_name')
                    ->label(__('laravel-crm-filament::labels.fields.name'))
                    ->state(function (Contact $record): ?string {
                        $person = $record->entityable;

                        if (! $person instanceof Person) {
                            return null;
                        }

                        return trim(($person->first_name ?? '') . ' ' . ($person->last_name ?? ''));
                    })
                    ->url(function (Contact $record): ?string {
                        $person = $record->entityable;

                        if (! $person instanceof Person) {
                            return null;
                        }

                        return PersonResource::getUrl('view', ['record' => $person]);
                    })
                    ->color('primary'),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->authorize(fn (): bool => $this->authorizeRelatedContact('create'))
                    ->icon('heroicon-m-plus')
                    ->color('gray')
                    ->hiddenLabel()
                    ->tooltip(__('laravel-crm-filament::labels.actions.add_person'))
                    ->modalHeading(__('laravel-crm-filament::labels.actions.add_person'))
                    ->modalSubmitActionLabel('Link')
                    ->createAnother(false)
                    ->using(function (array $data): Contact {
                        /** @var Person $owner */
                        $owner = $this->getOwnerRecord();

                        $related = Person::findOrFail($data['person_id']);

                        // Forward link: owner -> related
                        $contact = $owner->contacts()->create([
                            'entityable_type' => $related->getMorphClass(),
                            'entityable_id' => $related->id,
                        ]);

                        // Reciprocal link: related -> owner
                        $related->contacts()->firstOrCreate([
                            'entityable_type' => $owner->getMorphClass(),
                            'entityable_id' => $owner->id,
                        ]);

                        Notification::make()
                            ->title(__('laravel-crm-filament::labels.notifications.related_contact_added'))
                            ->success()
                            ->send();

                        return $contact;
                    }),
            ])
            ->recordActions([
                Actions\DeleteAction::make()
                    ->authorize(fn (?Contact $record): bool => $this->authorizeRelatedContact('delete', $record))
                    ->icon('heroicon-m-x-mark')
                    ->hiddenLabel()
                    ->tooltip('Remove')
                    ->color('danger')
                    ->using(function (Contact $record): void {
                        /** @var Person $owner */
                        $owner = $this->getOwnerRecord();
                        $relatedType = $record->entityable_type;
                        $relatedId = $record->entityable_id;

                        // Delete forward link
                        $record->delete();

                        // Delete reciprocal link
                        Contact::query()
                            ->where('contactable_type', $relatedType)
                            ->where('contactable_id', $relatedId)
                            ->where('entityable_type', $owner->getMorphClass())
                            ->where('entityable_id', $owner->id)
                            ->get()
                            ->each
                            ->delete();

                        Notification::make()
                            ->title(__('laravel-crm-filament::labels.notifications.related_contact_removed'))
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
