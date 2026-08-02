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
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrmFilament\Concerns\AuthorizesRelatedContacts;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\OrganizationResource;

class RelatedOrganizationsRelationManager extends RelationManager
{
    use AuthorizesRelatedContacts;

    protected static string $relationship = 'relatedOrganizationContacts';

    protected static ?string $title = 'Related organizations';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\Select::make('organization_id')
                ->label(__('laravel-crm-filament::labels.fields.organization'))
                ->options(fn () => Organization::query()->orderBy('name')->pluck('name', 'id')->toArray())
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
                        $org = $record->entityable;

                        return $org instanceof Organization ? $org->name : null;
                    })
                    ->url(function (Contact $record): ?string {
                        $org = $record->entityable;

                        if (! $org instanceof Organization) {
                            return null;
                        }

                        return OrganizationResource::getUrl('view', ['record' => $org]);
                    })
                    ->color('primary'),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->authorize(fn (): bool => $this->authorizeRelatedContact('create'))
                    ->icon('heroicon-m-plus')
                    ->color('gray')
                    ->hiddenLabel()
                    ->tooltip(__('laravel-crm-filament::labels.actions.add_organization'))
                    ->modalHeading(__('laravel-crm-filament::labels.actions.add_organization'))
                    ->modalSubmitActionLabel('Link')
                    ->createAnother(false)
                    ->using(function (array $data): Contact {
                        $owner = $this->getOwnerRecord();
                        $related = Organization::findOrFail($data['organization_id']);

                        $contact = $owner->contacts()->create([
                            'entityable_type' => $related->getMorphClass(),
                            'entityable_id' => $related->id,
                        ]);

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
                        $owner = $this->getOwnerRecord();
                        $relatedType = $record->entityable_type;
                        $relatedId = $record->entityable_id;

                        $record->delete();

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
