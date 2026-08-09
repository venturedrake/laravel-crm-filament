<?php

namespace VentureDrake\LaravelCrmFilament\Concerns\Imports;

use VentureDrake\LaravelCrm\Models\Email;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Services\OrganizationService;
use VentureDrake\LaravelCrm\Services\PersonService;
use VentureDrake\LaravelCrmFilament\Support\FormPayload;

class PersonImporter extends Importer
{
    public function permission(): ?string
    {
        return 'create crm people';
    }

    public function columns(): array
    {
        return [
            'first_name' => 'First name',
            'last_name' => 'Last name',
            'email' => 'Email',
            'phone' => 'Phone',
            'organization' => 'Organization',
        ];
    }

    public function dedupeField(): string
    {
        return 'email';
    }

    public function sampleRow(): array
    {
        return [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane.doe@example.com',
            'phone' => '+1 555 0100',
            'organization' => 'Acme Inc',
        ];
    }

    public function importRow(array $row): bool
    {
        $email = isset($row['email']) ? strtolower(trim((string) $row['email'])) : '';
        $firstName = trim((string) ($row['first_name'] ?? ''));
        $lastName = trim((string) ($row['last_name'] ?? ''));

        if ($email === '' && $firstName === '' && $lastName === '') {
            return false;
        }

        if ($email !== '' && $this->emailAlreadyExists($email)) {
            return false;
        }

        $organizationId = null;
        $orgName = trim((string) ($row['organization'] ?? ''));
        if ($orgName !== '') {
            $organizationId = $this->findOrCreateOrganization($orgName);
        }

        $payload = FormPayload::wrap([
            'title' => null,
            'first_name' => $firstName !== '' ? $firstName : null,
            'middle_name' => null,
            'last_name' => $lastName !== '' ? $lastName : null,
            'gender' => null,
            'birthday' => null,
            'description' => null,
            'user_owner_id' => null,
            'phones' => ! empty($row['phone'])
                ? [['id' => null, 'number' => $row['phone'], 'type' => null, 'primary' => 'on']]
                : [],
            'emails' => $email !== ''
                ? [['id' => null, 'address' => $email, 'type' => null, 'primary' => 'on']]
                : [],
            'addresses' => [],
        ]);

        $person = app(PersonService::class)->create($payload);

        if ($organizationId !== null) {
            $person->update(['organization_id' => $organizationId]);
        }

        return true;
    }

    protected function emailAlreadyExists(string $email): bool
    {
        if (! config('laravel-crm.encrypt_db_fields', false)) {
            return Email::where('address', $email)->exists();
        }

        foreach (Email::query()->cursor() as $existing) {
            if (strtolower((string) $existing->address) === $email) {
                return true;
            }
        }

        return false;
    }

    protected function findOrCreateOrganization(string $name): int
    {
        $needle = strtolower($name);

        if (! config('laravel-crm.encrypt_db_fields', false)) {
            if ($org = Organization::whereRaw('LOWER(name) = ?', [$needle])->first()) {
                return $org->id;
            }
        } else {
            foreach (Organization::query()->cursor() as $existing) {
                if (strtolower((string) $existing->name) === $needle) {
                    return $existing->id;
                }
            }
        }

        $payload = FormPayload::wrap([
            'name' => $name,
            'organization_type_id' => null,
            'vat_number' => null,
            'industry_id' => null,
            'timezone_id' => null,
            'number_of_employees' => null,
            'annual_revenue' => null,
            'total_money_raised' => null,
            'linkedin' => null,
            'description' => null,
            'user_owner_id' => null,
            'phones' => [],
            'emails' => [],
            'addresses' => [],
        ]);

        return app(OrganizationService::class)->create($payload)->id;
    }
}
