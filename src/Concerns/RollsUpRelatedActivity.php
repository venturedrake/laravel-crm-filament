<?php

namespace VentureDrake\LaravelCrmFilament\Concerns;

use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Throwable;

/**
 * Honours core CRM's `show_related_activity` setting on the inline-card
 * relation managers.
 *
 * When the setting is on, a record's activity list also shows the activity of
 * everything the record is related to — its `contacts` (each contact's
 * `entityable`), plus its `person` and `organization` where those relations
 * exist. That is what base does across its activity components
 * (`Livewire\Notes\NoteRelated`, `Livewire\Activities\ActivityIndex`, and the
 * rest of the `*Related` / `Live*` family).
 *
 * Base collects the rows with an N+1 loop — one `->activities()` / `->notes()`
 * query per contact, plus one to load each contact's `entityable`. This
 * concern instead pre-collects the morph pairs (`{type, id}`) that identify
 * the related entities and issues a single query with a `whereIn` over the
 * pre-collected ids per morph type. The contact rows already carry
 * `entityable_type` / `entityable_id`, so the related entities never have to
 * be loaded at all: the number of queries is fixed, no matter how many
 * related contacts a record has.
 */
trait RollsUpRelatedActivity
{
    /**
     * Column name for the badge that distinguishes a rolled-up row from one of
     * the owner record's own rows. Deliberately prefixed so it can never
     * collide with a real column on Note/Task/Call/Meeting/Lunch/File/Activity.
     */
    public const RELATED_ACTIVITY_COLUMN = 'crm_related_activity';

    /**
     * Morph pairs of the owner record's related entities, keyed by morph type.
     *
     * @var array<string, array<int, int|string>>|null
     */
    protected ?array $relatedActivityMorphPairs = null;

    /**
     * Whether core CRM's `show_related_activity` setting is switched on.
     *
     * Base compares the raw setting against `1` (`SettingService::get()` hands
     * back the scalar value, not a Setting model), so we do the same.
     */
    public static function showsRelatedActivity(): bool
    {
        if (! app()->bound('laravel-crm.settings')) {
            return false;
        }

        try {
            return (int) app('laravel-crm.settings')->get('show_related_activity') === 1;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The morph pairs of every entity whose activity rolls up to the owner
     * record, keyed by morph type and de-duplicated. The owner's own pair is
     * deliberately excluded — its rows are not "related" rows.
     *
     * Costs a single query (the contacts lookup); `person` / `organization`
     * are read off the owner's foreign key columns without loading the models.
     *
     * @return array<string, array<int, int|string>>
     */
    public function relatedActivityMorphPairs(): array
    {
        if ($this->relatedActivityMorphPairs !== null) {
            return $this->relatedActivityMorphPairs;
        }

        $owner = $this->getOwnerRecord();
        $pairs = [];

        // One query. Base loads `$contact->entityable` per contact purely to
        // reach its activity relation; the morph columns on the contact row
        // already carry everything the `whereIn` needs.
        $contactsRelation = 'contacts';

        if (method_exists($owner, $contactsRelation)) {
            foreach ($owner->{$contactsRelation}()->get(['entityable_type', 'entityable_id']) as $contact) {
                $type = $contact->getAttribute('entityable_type');
                $id = $contact->getAttribute('entityable_id');

                if (blank($type) || blank($id)) {
                    continue;
                }

                $pairs[$type][] = $id;
            }
        }

        // Zero queries: a BelongsTo exposes its foreign key, so the id and the
        // related model's morph class are both known without a lookup.
        foreach (['person', 'organization'] as $relationName) {
            if (! method_exists($owner, $relationName)) {
                continue;
            }

            $relation = $owner->{$relationName}();

            if ($relation instanceof BelongsTo) {
                $id = $owner->getAttribute($relation->getForeignKeyName());

                if (filled($id)) {
                    $pairs[$relation->getRelated()->getMorphClass()][] = $id;
                }

                continue;
            }

            $related = $owner->getAttribute($relationName);

            if ($related instanceof Model) {
                $pairs[$related->getMorphClass()][] = $related->getKey();
            }
        }

        return $this->relatedActivityMorphPairs = $this->normaliseMorphPairs(
            $pairs,
            $owner->getMorphClass(),
            $owner->getKey(),
        );
    }

    /**
     * The owner record's activity query, with the related entities' rows
     * unioned in when the setting allows it.
     */
    public function rolledUpActivityQuery(): Builder | Relation
    {
        $relationship = $this->getRelationship();

        if (! $relationship instanceof MorphMany) {
            return $relationship;
        }

        if (! static::showsRelatedActivity()) {
            return $relationship;
        }

        $pairs = $this->relatedActivityMorphPairs();

        if ($pairs === []) {
            return $relationship;
        }

        $owner = $this->getOwnerRecord();
        $typeColumn = $relationship->getQualifiedMorphType();
        $idColumn = $relationship->getQualifiedForeignKeyName();

        $pairs = $this->normaliseMorphPairs(
            array_merge_recursive($pairs, [$owner->getMorphClass() => [$owner->getKey()]]),
        );

        // A single grouped where — the owner's own rows OR the pre-collected
        // related pairs. Built off a fresh model query rather than the
        // relationship so the morph constraint is replaced, not OR'd onto,
        // which keeps any search/filter Filament applies afterwards ANDed
        // across the whole set.
        return $relationship->getRelated()->newQuery()
            ->where(function (Builder $query) use ($pairs, $typeColumn, $idColumn): void {
                foreach ($pairs as $type => $ids) {
                    $query->orWhere(
                        fn (Builder $query): Builder => $query
                            ->where($typeColumn, $type)
                            ->whereIn($idColumn, $ids)
                    );
                }
            });
    }

    /**
     * The rows to render, newest first, own rows and rolled-up rows together.
     */
    public function relatedActivityRows(string $orderColumn = 'created_at', string $direction = 'desc'): EloquentCollection
    {
        /** @var EloquentCollection<int, Model> $rows */
        $rows = $this->rolledUpActivityQuery()->orderBy($orderColumn, $direction)->get();

        return $rows;
    }

    /**
     * Look a row up across the rolled-up set — the owner's own rows plus the
     * related entities' rows.
     *
     * Mutating handlers deliberately do NOT use this: a rolled-up row belongs
     * to another record and is read-only here (the views hide its edit/delete
     * controls). Read-only handlers such as the file download link do, so a
     * rolled-up row is not rendered without one.
     */
    public function findRolledUpActivityRecord(int | string $id): ?Model
    {
        /** @var Model|null $record */
        $record = $this->rolledUpActivityQuery()->whereKey($id)->first();

        return $record;
    }

    /**
     * Whether a row was rolled up from a related entity rather than belonging
     * to the owner record itself.
     */
    public function isRelatedActivityRecord(?Model $record): bool
    {
        if ($record === null) {
            return false;
        }

        $relationship = $this->getRelationship();

        if (! $relationship instanceof MorphMany) {
            return false;
        }

        $owner = $this->getOwnerRecord();

        $type = $record->getAttribute($relationship->getMorphType());
        $id = $record->getAttribute($this->unqualifyColumn($relationship->getQualifiedForeignKeyName()));

        if (blank($type) || blank($id)) {
            return false;
        }

        return ! (
            $type === $owner->getMorphClass()
            && (string) $id === (string) $owner->getKey()
        );
    }

    /**
     * The "Related" badge column, shown only while the setting is on.
     */
    public function relatedActivityColumn(): Tables\Columns\TextColumn
    {
        return Tables\Columns\TextColumn::make(static::RELATED_ACTIVITY_COLUMN)
            ->label(__('laravel-crm-filament::labels.fields.related'))
            ->badge()
            ->color('warning')
            ->state(fn (Model $record): ?string => $this->isRelatedActivityRecord($record)
                ? __('laravel-crm-filament::labels.fields.related')
                : null)
            ->visible(fn (): bool => static::showsRelatedActivity());
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->pushColumns([$this->relatedActivityColumn()]);
    }

    /**
     * Filament resolves the table's records from here in preference to the
     * relationship query (@see \Filament\Tables\Table\Concerns\HasQuery::getQuery()),
     * so returning null falls back to the plain relationship.
     */
    protected function getTableQuery(): Builder | Relation | null
    {
        if (! static::showsRelatedActivity()) {
            return null;
        }

        if ($this->relatedActivityMorphPairs() === []) {
            return null;
        }

        return $this->rolledUpActivityQuery();
    }

    /**
     * De-duplicate the collected pairs and optionally drop one of them.
     *
     * @param  array<string, array<int, int|string>>  $pairs
     * @return array<string, array<int, int|string>>
     */
    protected function normaliseMorphPairs(array $pairs, ?string $excludeType = null, int | string | null $excludeId = null): array
    {
        $normalised = [];

        foreach ($pairs as $type => $ids) {
            $ids = array_values(array_unique(array_map(
                fn (int | string $id): string => (string) $id,
                $ids,
            )));

            if ($excludeType !== null && $type === $excludeType && $excludeId !== null) {
                $ids = array_values(array_filter(
                    $ids,
                    fn (string $id): bool => $id !== (string) $excludeId,
                ));
            }

            if ($ids === []) {
                continue;
            }

            $normalised[$type] = $ids;
        }

        return $normalised;
    }

    protected function unqualifyColumn(string $column): string
    {
        return str_contains($column, '.')
            ? substr($column, (int) strrpos($column, '.') + 1)
            : $column;
    }
}
