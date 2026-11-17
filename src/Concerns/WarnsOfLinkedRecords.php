<?php

namespace VentureDrake\LaravelCrmFilament\Concerns;

use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The delete dialogs on people and organizations warn about the records still
 * linked to the contact, as core 2.7.0's do (#102). Deletion still goes ahead:
 * the leads, deals and documents keep the reference and show the contact as
 * deleted.
 *
 * The warning text is core's `deleteWarning()` (HasLinkedRecordsWarning). The
 * list query loads each linked relation's count with withCount(), which
 * deleteWarning() reads, so a row's dialog doesn't query per row.
 */
trait WarnsOfLinkedRecords
{
    public static function withLinkedRecordCounts(Builder $query): Builder
    {
        $model = $query->getModel();

        // HasLinkedRecordsWarning (core 2.7.0) on Person and Organization.
        if (! method_exists($model, 'linkedRecordRelations')) {
            return $query;
        }

        return $query->withCount($model->linkedRecordRelations());
    }

    public static function warnOfLinkedRecords(DeleteAction $action): DeleteAction
    {
        return $action->modalDescription(
            fn (Model $record): string => static::linkedRecordsWarning($record) ?? __('filament-actions::modal.confirmation'),
        );
    }

    /**
     * One linked contact gets its own warning; several get a count, since a
     * sentence per contact would bury the confirm button.
     */
    public static function warnOfLinkedRecordsInBulk(DeleteBulkAction $action): DeleteBulkAction
    {
        return $action->modalDescription(function (?Collection $records): string {
            $warnings = collect($records ?? [])
                ->map(fn (Model $record): ?string => static::linkedRecordsWarning($record))
                ->filter()
                ->values();

            return match ($warnings->count()) {
                0 => __('filament-actions::modal.confirmation'),
                1 => (string) $warnings->first(),
                default => __('laravel-crm-filament::labels.contact.bulk_delete_linked_warning', ['count' => $warnings->count()]),
            };
        });
    }

    protected static function linkedRecordsWarning(Model $record): ?string
    {
        return method_exists($record, 'deleteWarning') ? $record->deleteWarning() : null;
    }
}
