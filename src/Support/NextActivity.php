<?php

namespace VentureDrake\LaravelCrmFilament\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The "Next activity" cell on the people and organizations tables: the due
 * date of the soonest incomplete task that is not already overdue.
 *
 * Read per row, that is one tasks() query per row — the N+1 core 2.4.3 cut out
 * of its index pages (core dropped its own column outright, since it read an
 * attribute that never existed). Here the list query carries it as a single
 * withMin() subselect instead, and the cell reads that.
 */
class NextActivity
{
    public const ATTRIBUTE = 'next_activity_at';

    public static function eagerLoad(Builder $query): Builder
    {
        return $query->withMin([
            'tasks as ' . self::ATTRIBUTE => fn ($q) => $q
                ->whereNull('completed_at')
                ->where('due_at', '>=', now()),
        ], 'due_at');
    }

    /**
     * Off the subselect when the row came from the list query, out of the
     * database when it didn't.
     */
    public static function for(?Model $record): mixed
    {
        if ($record === null) {
            return null;
        }

        if (array_key_exists(self::ATTRIBUTE, $record->getAttributes())) {
            return $record->getAttribute(self::ATTRIBUTE);
        }

        return $record->tasks()
            ->whereNull('completed_at')
            ->where('due_at', '>=', now())
            ->orderBy('due_at')
            ->first()?->due_at;
    }
}
