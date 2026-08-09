<?php

namespace VentureDrake\LaravelCrmFilament\Concerns\Imports;

use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Services\OrganizationService;
use VentureDrake\LaravelCrmFilament\Support\FormPayload;

class OrganizationImporter extends Importer
{
    public function permission(): ?string
    {
        return 'create crm organizations';
    }

    public function columns(): array
    {
        return [
            'name' => 'Name',
            'vat' => 'VAT',
            'employees' => 'Employees',
            'revenue' => 'Annual revenue',
            'linkedin' => 'LinkedIn',
        ];
    }

    public function dedupeField(): string
    {
        return 'name';
    }

    public function sampleRow(): array
    {
        return [
            'name' => 'Acme Inc',
            'vat' => 'GB123456789',
            'employees' => '120',
            'revenue' => '5000000',
            'linkedin' => 'https://www.linkedin.com/company/acme',
        ];
    }

    public function importRow(array $row): bool
    {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            return false;
        }

        if ($this->nameAlreadyExists($name)) {
            return false;
        }

        $employees = isset($row['employees']) && $row['employees'] !== ''
            ? (int) $row['employees']
            : null;

        $revenue = isset($row['revenue']) && $row['revenue'] !== ''
            ? (int) $row['revenue']
            : null;

        $payload = FormPayload::wrap([
            'name' => $name,
            'organization_type_id' => null,
            'vat_number' => $row['vat'] ?? null,
            'industry_id' => null,
            'timezone_id' => null,
            'number_of_employees' => $employees,
            'annual_revenue' => $revenue,
            'total_money_raised' => null,
            'linkedin' => $row['linkedin'] ?? null,
            'description' => null,
            'user_owner_id' => null,
            'phones' => [],
            'emails' => [],
            'addresses' => [],
        ]);

        app(OrganizationService::class)->create($payload);

        return true;
    }

    protected function nameAlreadyExists(string $name): bool
    {
        $needle = strtolower($name);

        if (! config('laravel-crm.encrypt_db_fields', false)) {
            return Organization::whereRaw('LOWER(name) = ?', [$needle])->exists();
        }

        foreach (Organization::query()->cursor() as $existing) {
            if (strtolower((string) $existing->name) === $needle) {
                return true;
            }
        }

        return false;
    }
}
