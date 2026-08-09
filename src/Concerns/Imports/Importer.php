<?php

namespace VentureDrake\LaravelCrmFilament\Concerns\Imports;

/**
 * Base class for per-resource CSV importers used by ImportsCsv.
 *
 * Each subclass declares:
 *  - columns(): destination_key => friendly CSV header label
 *  - dedupeField(): destination_key whose value (lowercased + trimmed) is
 *    used to skip duplicates within the same import batch and against rows
 *    already in the database
 *  - sampleRow(): one example row (destination_key => value) used by the
 *    "Download sample CSV" modal action
 *  - importRow(array $row): create the record through the core CRM service
 *    (or skip and return false). $row is keyed by destination_key.
 *
 * Importers must call the core service so observers/audits/encryption fire.
 */
abstract class Importer
{
    /**
     * Destination key => friendly CSV header label.
     *
     * @return array<string, string>
     */
    abstract public function columns(): array;

    /**
     * Destination key used for dedupe (lowercased + trimmed).
     */
    abstract public function dedupeField(): string;

    /**
     * Single example row keyed by destination key. Used for sample CSV.
     *
     * @return array<string, string|int|null>
     */
    abstract public function sampleRow(): array;

    /**
     * Create the record from a single mapped row. Return true if a record
     * was created, false if the row was skipped (duplicate / invalid).
     *
     * @param  array<string, string|null>  $row
     */
    abstract public function importRow(array $row): bool;

    /**
     * The CRM permission required to run this import, or null when the
     * importer is deliberately unrestricted.
     *
     * Importing is a create — an import that bypasses the permission its
     * resource's Create action enforces is a hole, and for UserImporter it was
     * a hole that let any panel user bulk-create accounts with crm_access = 1.
     * ImportsCsv applies this both as ->visible() and as a server-side
     * abort_unless(), because hiding a button is not authorization.
     */
    public function permission(): ?string
    {
        return null;
    }

    /**
     * Filename stem (no extension) used for the sample CSV download.
     */
    public function sampleFilename(): string
    {
        $short = (new \ReflectionClass($this))->getShortName();

        return strtolower(str_replace('Importer', '', $short)) . '-sample';
    }

    /**
     * Normalised dedupe value (lowercased + trimmed). Returns null for blanks.
     */
    public function dedupeValue(array $row): ?string
    {
        $value = $row[$this->dedupeField()] ?? null;

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return strtolower(trim((string) $value));
    }
}
