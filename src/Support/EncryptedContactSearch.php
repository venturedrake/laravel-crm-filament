<?php

namespace VentureDrake\LaravelCrmFilament\Support;

use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Collection;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Support\RecordSearch;

/**
 * Person and organization name search that still works with
 * `laravel-crm.encrypt_db_fields` on.
 *
 * Those names are stored as ciphertext then, so a SQL LIKE compares the term
 * with ciphertext and finds nothing, an ORDER BY sorts noise, and a
 * query-builder pluck('name') hands back the ciphertext itself — it bypasses
 * the model accessor that decrypts. Core fixed its own autocompletes at 2.4.6
 * by decrypting in PHP and matching there (SearchesEncryptableContacts); this
 * is the panel's equivalent, for its relationship Selects and the
 * title-or-contact table searches.
 *
 * With encryption off every entry point leaves the SQL path untouched.
 */
class EncryptedContactSearch
{
    private const CHUNK_SIZE = 500;

    /**
     * How many people a guest search returns, as core 2.7.0's
     * HasGuestSearch::GUEST_SEARCH_LIMIT.
     */
    public const GUEST_SEARCH_LIMIT = 20;

    public static function enabled(): bool
    {
        return (bool) config('laravel-crm.encrypt_db_fields', false);
    }

    /**
     * Swap a person relationship Select's search and preload onto decrypted
     * names. The selected value's label already resolves through the model,
     * so it is left on the relationship.
     */
    public static function personSelect(Select $select): Select
    {
        if (! static::enabled()) {
            return $select;
        }

        return $select
            ->options(fn (Select $component): array => static::personOptions(null, $component->getOptionsLimit()))
            ->getSearchResultsUsing(fn (Select $component, ?string $search): array => static::personOptions($search, $component->getOptionsLimit()));
    }

    public static function organizationSelect(Select $select): Select
    {
        if (! static::enabled()) {
            return $select;
        }

        return $select
            ->options(fn (Select $component): array => static::organizationOptions(null, $component->getOptionsLimit()))
            ->getSearchResultsUsing(fn (Select $component, ?string $search): array => static::organizationOptions($search, $component->getOptionsLimit()));
    }

    /**
     * A multi-select of people searched on the server as the user types, like
     * the Guests picker on core 2.7.0's calls, meetings and lunches
     * (HasGuestSearch): nothing is preloaded, a search returns at most
     * $limit people, and only the chosen people's labels are looked up.
     */
    public static function personSearchSelect(Select $select, int $limit = self::GUEST_SEARCH_LIMIT): Select
    {
        return $select
            ->searchable()
            ->preload(false)
            ->options([])
            ->optionsLimit($limit)
            ->getSearchResultsUsing(fn (?string $search): array => static::personOptions($search, $limit, blankMatchesAll: false))
            ->getOptionLabelsUsing(fn (array $values): array => static::personLabels($values));
    }

    /**
     * id => "First Last" for the given person ids, sorted by name.
     *
     * @param  array<int|string>  $ids
     * @return array<int|string, string>
     */
    public static function personLabels(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        return Person::query()
            ->whereKey($ids)
            ->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn (Person $person): array => [$person->getKey() => static::personName($person)])
            ->sort(fn (string $a, string $b): int => strcasecmp($a, $b))
            ->all();
    }

    /**
     * id => "First Last" for the people matching $term, sorted by name and
     * capped at $limit. A blank term matches everyone, or no one when
     * $blankMatchesAll is false (a search picker before anything is typed).
     *
     * With encryption off the match runs in SQL, as core 2.7.0's guest
     * search does; with it on the names are ciphertext, so they are
     * decrypted and matched in PHP.
     *
     * @return array<int|string, string>
     */
    public static function personOptions(?string $term, ?int $limit = null, bool $blankMatchesAll = true): array
    {
        $term = trim((string) $term);

        if ($term === '' && ! $blankMatchesAll) {
            return [];
        }

        if (! static::enabled()) {
            return Person::query()
                ->when($term !== '', fn (Builder $query) => $query->where(
                    fn (Builder $query) => static::orPersonNameLike($query, $term),
                ))
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->orderBy('id')
                ->when($limit !== null, fn (Builder $query) => $query->limit($limit))
                ->get(['id', 'first_name', 'last_name'])
                ->mapWithKeys(fn (Person $person): array => [$person->getKey() => static::personName($person)])
                ->all();
        }

        return static::people($term)
            ->mapWithKeys(fn (Person $person): array => [$person->getKey() => static::personName($person)])
            ->sort(fn (string $a, string $b): int => strcasecmp($a, $b))
            ->when($limit !== null, fn (Collection $options) => $options->take($limit))
            ->all();
    }

    /**
     * id => name for the organizations matching $term (all of them when it is
     * blank), sorted by name and capped at $limit. Plain SQL with encryption
     * off.
     *
     * @return array<int|string, string>
     */
    public static function organizationOptions(?string $term, ?int $limit = null): array
    {
        if (! static::enabled()) {
            $term = trim((string) $term);

            return Organization::query()
                ->when($term !== '', fn (Builder $query) => $query->where('name', 'like', "%{$term}%"))
                ->orderBy('name')
                ->when($limit !== null, fn (Builder $query) => $query->limit($limit))
                ->pluck('name', 'id')
                ->all();
        }

        return static::organizations($term)
            ->mapWithKeys(fn (Organization $organization): array => [$organization->getKey() => (string) $organization->name])
            ->sort(fn (string $a, string $b): int => strcasecmp($a, $b))
            ->when($limit !== null, fn (Collection $options) => $options->take($limit))
            ->all();
    }

    /**
     * Title, or the linked person's or organization's name — the search on
     * the lead, deal and quote tables.
     */
    public static function titleOrContact(Builder $query, string $search): Builder
    {
        if (! static::enabled()) {
            return $query
                ->where('title', 'like', "%{$search}%")
                ->orWhereHas('person', function (Builder $q) use ($search): void {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('middle_name', 'like', "%{$search}%")
                        ->orWhere('maiden_name', 'like', "%{$search}%");
                })
                ->orWhereHas('organization', function (Builder $q) use ($search): void {
                    $q->where('name', 'like', "%{$search}%");
                });
        }

        return $query
            ->where('title', 'like', "%{$search}%")
            ->orWhereIn('person_id', static::people($search)->pluck('id')->all())
            ->orWhereIn('organization_id', static::organizations($search)->pluck('id')->all());
    }

    /**
     * @return Collection<int, Person>
     */
    protected static function people(?string $term): Collection
    {
        $needle = mb_strtolower(trim((string) $term));
        $matches = new Collection;

        Person::query()
            ->select(['id', 'first_name', 'middle_name', 'last_name', 'maiden_name'])
            ->chunkById(self::CHUNK_SIZE, function ($people) use ($needle, $matches): void {
                foreach ($people as $person) {
                    if ($needle === '' || static::personMatches($person, $needle)) {
                        $matches->push($person);
                    }
                }
            });

        return $matches;
    }

    /**
     * @return Collection<int, Organization>
     */
    protected static function organizations(?string $term): Collection
    {
        $needle = mb_strtolower(trim((string) $term));
        $matches = new Collection;

        Organization::query()
            ->select(['id', 'name'])
            ->chunkById(self::CHUNK_SIZE, function ($organizations) use ($needle, $matches): void {
                foreach ($organizations as $organization) {
                    if ($needle === '' || str_contains(mb_strtolower((string) $organization->name), $needle)) {
                        $matches->push($organization);
                    }
                }
            });

        return $matches;
    }

    /**
     * `or` a LIKE on each name column and on "First Last", with core's
     * RecordSearch so `%` and `_` in the term match literally.
     */
    protected static function orPersonNameLike(Builder $query, string $term): void
    {
        foreach (['first_name', 'last_name', 'middle_name', 'maiden_name'] as $column) {
            RecordSearch::orLike($query, $column, $term);
        }

        $grammar = $query->getQuery()->getGrammar();
        $first = $grammar->wrap('first_name');
        $last = $grammar->wrap('last_name');

        // SQLite before 3.44 has no CONCAT(), and MySQL reads || as OR.
        $fullName = in_array($query->getModel()->getConnection()->getDriverName(), ['sqlite', 'pgsql'], true)
            ? "({$first} || ' ' || {$last})"
            : "CONCAT({$first}, ' ', {$last})";

        RecordSearch::orLike($query, new Expression($fullName), $term);
    }

    protected static function personMatches(Person $person, string $needle): bool
    {
        foreach ([static::personName($person), $person->middle_name, $person->maiden_name] as $value) {
            if (str_contains(mb_strtolower((string) $value), $needle)) {
                return true;
            }
        }

        return false;
    }

    protected static function personName(Person $person): string
    {
        return trim(($person->first_name ?? '') . ' ' . ($person->last_name ?? ''));
    }
}
