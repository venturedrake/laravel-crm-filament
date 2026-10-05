<?php

namespace VentureDrake\LaravelCrmFilament\Support;

use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;

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
     * id => "First Last" for the people matching $term (all of them when it
     * is blank), sorted by decrypted name and capped at $limit.
     *
     * @return array<int|string, string>
     */
    public static function personOptions(?string $term, ?int $limit = null): array
    {
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
