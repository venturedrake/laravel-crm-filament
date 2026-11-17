<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Organizations\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Services\OrganizationService;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\OrganizationResource;
use VentureDrake\LaravelCrmFilament\Support\FormPayload;

class EditOrganization extends EditRecord
{
    protected static string $resource = OrganizationResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\ViewAction::make()
            ->button()
            ->hiddenLabel()
            ->icon('heroicon-m-eye'), OrganizationResource::warnOfLinkedRecords(Actions\DeleteAction::make())];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Organization $record */
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

        return OrganizationResource::loadCrmCustomFieldsInto($data, $this->getRecord());
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Organization $record */
        app(OrganizationService::class)->update($record, FormPayload::wrap($data));
        OrganizationResource::saveCrmCustomFields($data, $record);

        return $record->refresh();
    }

    protected function getAllRelationManagers(): array
    {
        return [];
    }
}
