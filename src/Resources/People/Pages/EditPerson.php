<?php

namespace VentureDrake\LaravelCrmFilament\Resources\People\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Services\PersonService;
use VentureDrake\LaravelCrmFilament\Resources\People\PersonResource;
use VentureDrake\LaravelCrmFilament\Support\FormPayload;

class EditPerson extends EditRecord
{
    protected static string $resource = PersonResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\ViewAction::make()
            ->button()
            ->hiddenLabel()
            ->icon('heroicon-m-eye'), PersonResource::warnOfLinkedRecords(Actions\DeleteAction::make())];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Person $record */
        $record = $this->getRecord();

        // A number or address saved without a type opens as mobile / work,
        // as core 2.7.0's edit forms do, now that the type is required.
        $data['phones'] = $record->phones->map(fn ($p) => [
            'id' => $p->id,
            'number' => $p->number,
            'type' => $p->type ?: 'mobile',
            'primary' => (bool) $p->primary,
        ])->values()->toArray();

        $data['emails'] = $record->emails->map(fn ($e) => [
            'id' => $e->id,
            'address' => $e->address,
            'type' => $e->type ?: 'work',
            'primary' => (bool) $e->primary,
        ])->values()->toArray();

        $data['addresses'] = $record->addresses->map(fn ($a) => [
            'id' => $a->id,
            'line1' => $a->line1,
            'line2' => $a->line2,
            'line3' => $a->line3,
            'city' => $a->city,
            'state' => $a->state,
            'code' => $a->code,
            'country' => $a->country,
            'primary' => (bool) $a->primary,
        ])->values()->toArray();

        return PersonResource::loadCrmCustomFieldsInto($data, $this->getRecord());
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Person $record */
        app(PersonService::class)->update($record, FormPayload::wrap($data));
        PersonResource::saveCrmCustomFields($data, $record);

        return $record->refresh();
    }

    protected function getAllRelationManagers(): array
    {
        return [];
    }
}
