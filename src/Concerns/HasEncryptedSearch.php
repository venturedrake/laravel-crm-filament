<?php

namespace VentureDrake\LaravelCrmFilament\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Adds an encrypted-field aware modifyQueryUsing callback to a Filament table.
 *
 * When LARAVEL_CRM_ENCRYPT_DB_FIELDS is true, the named columns contain cipher
 * text — SQL LIKE searches are meaningless. This concern:
 *  1. Marks those columns as NOT searchable (suppresses Filament's default SQL search).
 *  2. Provides a table->modifyQueryUsing() callback that performs a PHP-side
 *     decrypt-and-compare when a global search term is active.
 *
 * Usage (in a Resource table() method) — the second argument MUST list every
 * column the accessor reads, otherwise those attributes are never loaded and
 * the accessor silently sees null:
 *   ->modifyQueryUsing(HasEncryptedSearch::modifyQuery(
 *       fn ($record) => $record->first_name.' '.$record->last_name,
 *       ['first_name', 'last_name']
 *   ))
 */
class HasEncryptedSearch
{
    /**
     * Number of records decrypted per chunk while scanning for matches.
     */
    private const CHUNK_SIZE = 500;

    /**
     * Return a modifyQueryUsing closure that handles encrypted-field search.
     *
     * @param  Closure(mixed): string  $accessor  Returns the plain-text string to search against.
     * @param  list<string>  $requiredColumns  Columns the accessor reads; the primary key is always added.
     * @param  list<string>  $sqlSearchColumns  Columns that CAN be searched via SQL (non-encrypted).
     */
    public static function modifyQuery(
        Closure $accessor,
        array $requiredColumns = ['*'],
        array $sqlSearchColumns = []
    ): Closure {
        return function (Builder $query) use ($accessor, $requiredColumns) {
            if (! config('laravel-crm.encrypt_db_fields', false)) {
                return $query;
            }

            $search = request('tableSearch') ?? request('search') ?? '';

            if (blank($search)) {
                return $query;
            }

            // Collect matching IDs in PHP (decrypt then match), a chunk at a
            // time so the whole table never sits in memory at once.
            $term = mb_strtolower($search);
            $keyName = $query->getModel()->getKeyName();
            $matchingIds = [];

            $query->getModel()::withoutGlobalScopes()
                ->select(self::columns($keyName, $requiredColumns))
                ->chunkById(self::CHUNK_SIZE, function ($records) use ($accessor, $term, $keyName, &$matchingIds) {
                    foreach ($records as $record) {
                        if (str_contains(mb_strtolower((string) $accessor($record)), $term)) {
                            $matchingIds[] = $record->{$keyName};
                        }
                    }
                }, $keyName);

            return $query->whereIn($keyName, $matchingIds);
        };
    }

    /**
     * Build the select list: the requested columns plus the primary key, which
     * chunkById() needs to page and which supplies the whereIn values.
     *
     * @param  list<string>  $requiredColumns
     * @return list<string>
     */
    private static function columns(string $keyName, array $requiredColumns): array
    {
        if ($requiredColumns === [] || in_array('*', $requiredColumns, true)) {
            return ['*'];
        }

        return array_values(array_unique([$keyName, ...$requiredColumns]));
    }
}
