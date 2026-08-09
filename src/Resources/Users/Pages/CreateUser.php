<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Users\Pages;

use Filament\Resources\Pages\CreateRecord;
use VentureDrake\LaravelCrm\Models\Address;
use VentureDrake\LaravelCrm\Models\Phone;
use VentureDrake\LaravelCrm\Models\Role;
use VentureDrake\LaravelCrmFilament\Resources\Users\UserResource;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected ?int $roleId = null;

    /** @var array<int, int>|null */
    protected ?array $crmTeamIds = null;

    /** @var array<int, array<string, mixed>> */
    protected array $phonesPayload = [];

    /** @var array<int, array<string, mixed>> */
    protected array $addressesPayload = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->roleId = isset($data['role_id']) ? (int) $data['role_id'] : null;

        // See EditUser: absent means the teams module is off, not "no teams".
        $this->crmTeamIds = array_key_exists('crm_team_ids', $data)
            ? collect($data['crm_team_ids'] ?? [])->filter()->map(fn ($id) => (int) $id)->all()
            : null;
        $this->phonesPayload = $data['phones'] ?? [];
        $this->addressesPayload = $data['addresses'] ?? [];

        unset($data['role_id'], $data['crm_team_ids'], $data['phones'], $data['addresses']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $record = $this->record;

        // Re-checked here as well as in the AssignableRole rule — see EditUser.
        if ($this->roleId !== null) {
            $role = Role::assignableBy()->whereKey($this->roleId)->first();
            if ($role) {
                $record->syncRoles([$role]);
            }
        }

        if ($this->crmTeamIds !== null && method_exists($record, 'crmTeams')) {
            $record->crmTeams()->sync($this->crmTeamIds);
        }

        UserResource::syncMorphRows($record, 'phones', $this->phonesPayload, Phone::class, ['number', 'type']);
        UserResource::syncMorphRows($record, 'addresses', $this->addressesPayload, Address::class, ['line1', 'line2', 'line3', 'city', 'state', 'code', 'country']);
    }
}
