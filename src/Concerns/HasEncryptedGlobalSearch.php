<?php

namespace VentureDrake\LaravelCrmFilament\Concerns;

use Closure;
use Filament\GlobalSearch\GlobalSearchResult;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Augments a Filament Resource's global search to handle encrypted name fields.
 *
 * When `laravel-crm.encrypt_db_fields` is enabled the searchable columns contain
 * cipher text; standard LIKE search never matches. This trait overrides
 * getGlobalSearchResults() to do a PHP-side decrypt + substring compare instead.
 *
 * The PHP-side path is strictly worse than SQL whenever it is not needed — it
 * cannot be pushed down to the database — so it is taken only when BOTH the
 * `encrypt_db_fields` setting is on AND the resource's model actually declares
 * encryptable attributes. Models such as Deal and Lead store their searchable
 * columns in plain text regardless of the setting, and must keep using SQL.
 *
 * Hosts must still expose `getGloballySearchableAttributes()` (for the un-encrypted
 * case) and `getGlobalSearchResultTitle()` / `Details()`. Resources that want this
 * behavior implement `crmEncryptedSearchAccessor()` returning the plaintext
 * string to compare against per record.
 */
trait HasEncryptedGlobalSearch
{
    /**
     * Records decrypted per chunk while scanning for matches. Mirrors
     * {@see HasEncryptedSearch}, which solves the same problem for tables.
     */
    protected const CRM_ENCRYPTED_SEARCH_CHUNK_SIZE = 500;

    public static function getGlobalSearchResults(string $search): Collection
    {
        if (! static::crmUsesEncryptedGlobalSearch()) {
            return parent::getGlobalSearchResults($search);
        }

        $term = trim($search);
        if ($term === '') {
            return collect();
        }

        $needle = mb_strtolower($term);
        $accessor = static::crmEncryptedSearchAccessor();
        $limit = static::getGlobalSearchResultsLimit();

        $matches = [];

        // Scan the whole table a chunk at a time rather than an arbitrary
        // window: a decrypted match can sit anywhere in the table, so any
        // fixed prefix would silently drop results. Stops as soon as the
        // result limit is filled.
        static::getGlobalSearchEloquentQuery()->chunkById(
            static::CRM_ENCRYPTED_SEARCH_CHUNK_SIZE,
            function (EloquentCollection $records) use ($accessor, $needle, $limit, &$matches): bool {
                foreach ($records as $record) {
                    if (! str_contains(mb_strtolower((string) $accessor($record)), $needle)) {
                        continue;
                    }

                    $matches[] = $record;

                    if (count($matches) >= $limit) {
                        return false;
                    }
                }

                return true;
            },
        );

        return collect($matches)
            ->map(fn (Model $record): ?GlobalSearchResult => static::crmGlobalSearchResult($record))
            ->filter()
            ->values();
    }

    /**
     * Whether this resource's records really need the PHP-side scan: the
     * setting is on AND the model declares encryptable attributes.
     */
    protected static function crmUsesEncryptedGlobalSearch(): bool
    {
        if (! config('laravel-crm.encrypt_db_fields', false)) {
            return false;
        }

        $model = static::getModel();
        $instance = new $model;

        if (! method_exists($instance, 'getEncryptable')) {
            return false;
        }

        return (array) $instance->getEncryptable() !== [];
    }

    /**
     * Filament's GlobalSearchResult takes a non-nullable string $url, and
     * getGlobalSearchResultUrl() returns null for a record the user may
     * neither view nor edit. Skip those rather than raising a TypeError.
     */
    protected static function crmGlobalSearchResult(Model $record): ?GlobalSearchResult
    {
        $url = static::getGlobalSearchResultUrl($record);

        if (blank($url)) {
            return null;
        }

        return new GlobalSearchResult(
            title: static::getGlobalSearchResultTitle($record),
            url: $url,
            details: static::getGlobalSearchResultDetails($record),
            actions: static::getGlobalSearchResultActions($record),
        );
    }

    /**
     * Returns a closure that, given a record, returns the plaintext haystack
     * to substring-match against.
     */
    abstract protected static function crmEncryptedSearchAccessor(): Closure;
}
