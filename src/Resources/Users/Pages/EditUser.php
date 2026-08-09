<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Users\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use VentureDrake\LaravelCrm\Models\Address;
use VentureDrake\LaravelCrm\Models\Phone;
use VentureDrake\LaravelCrm\Models\Role;
use VentureDrake\LaravelCrmFilament\Resources\Users\UserResource;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected ?int $roleId = null;

    /** @var array<int, int>|null */
    protected ?array $crmTeamIds = null;

    /** @var array<int, array<string, mixed>> */
    protected array $phonesPayload = [];

    /** @var array<int, array<string, mixed>> */
    protected array $addressesPayload = [];

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        $data['role_id'] = optional($record->roles()->first())->id;

        $data['crm_team_ids'] = method_exists($record, 'crmTeams')
            ? $record->crmTeams()->pluck('crm_teams.id')->all()
            : [];

        $data['phones'] = method_exists($record, 'phones')
            ? $record->phones()->get()->map(fn ($phone) => [
                'id' => $phone->id,
                'number' => $phone->number,
                'type' => $phone->type,
                'primary' => (bool) $phone->primary,
            ])->all()
            : [];

        $data['addresses'] = method_exists($record, 'addresses')
            ? $record->addresses()->get()->map(fn ($address) => [
                'id' => $address->id,
                'line1' => $address->line1,
                'line2' => $address->line2,
                'line3' => $address->line3,
                'city' => $address->city,
                'state' => $address->state,
                'code' => $address->code,
                'country' => $address->country,
                'primary' => (bool) $address->primary,
            ])->all()
            : [];

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->roleId = isset($data['role_id']) ? (int) $data['role_id'] : null;

        // Absent (rather than empty) means the field was not on the form at all
        // — the teams module is off — so the existing memberships must be left
        // alone rather than synced away.
        $this->crmTeamIds = array_key_exists('crm_team_ids', $data)
            ? collect($data['crm_team_ids'] ?? [])->filter()->map(fn ($id) => (int) $id)->all()
            : null;
        $this->phonesPayload = $data['phones'] ?? [];
        $this->addressesPayload = $data['addresses'] ?? [];

        unset($data['role_id'], $data['crm_team_ids'], $data['phones'], $data['addresses']);

        return $data;
    }

    protected function afterSave(): void
    {
        $record = $this->record;

        // Re-check entitlement at the assignment site as well as in the
        // AssignableRole rule: core does both deliberately, and a role that
        // stopped being assignable between render and save must not be handed
        // out just because the payload still carries its id.
        $role = $this->roleId !== null
            ? Role::assignableBy()->whereKey($this->roleId)->first()
            : null;

        if ($role) {
            $record->syncRoles([$role]);
        } else {
            $record->syncRoles([]);
        }

        if ($this->crmTeamIds !== null && method_exists($record, 'crmTeams')) {
            $record->crmTeams()->sync($this->crmTeamIds);
        }

        UserResource::syncMorphRows($record, 'phones', $this->phonesPayload, Phone::class, ['number', 'type']);
        UserResource::syncMorphRows($record, 'addresses', $this->addressesPayload, Address::class, ['line1', 'line2', 'line3', 'city', 'state', 'code', 'country']);
    }
}
