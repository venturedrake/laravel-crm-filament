<?php

namespace VentureDrake\LaravelCrmFilament\Concerns;

use Filament\Actions\Action;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrmFilament\Support\CrmPdf;
use VentureDrake\LaravelCrmFilament\Support\DocumentPreview;

/**
 * The "Preview" action — core 2.4.1's PDF drawer, as a Filament slide-over.
 *
 * One builder, used from both surfaces: the resources' static row-action
 * factories and the ViewRecord pages' instance-bound header actions. Same split
 * as {@see DownloadsPdf}, and the same reason — a table row action is static
 * and a page header action is not, so both entry points exist over one
 * implementation.
 *
 * The icon is `document-magnifying-glass`, not `eye`: the eye is already
 * Filament's ViewAction on every one of these rows, and core hit the same
 * collision and resolved it the same way at 2.4.1.
 */
trait HasPdfPreviewAction
{
    /**
     * Build the Preview action for `$type` (a {@see DocumentPreview::TYPES}
     * slug).
     */
    public static function pdfPreviewAction(string $type): Action
    {
        return Action::make('previewPdf')
            ->label(__('laravel-crm-filament::labels.actions.preview'))
            ->icon('heroicon-o-document-magnifying-glass')
            ->color('gray')
            ->slideOver()
            ->modalWidth('4xl')
            ->modalHeading(fn (Model $record): string => __('laravel-crm-filament::labels.actions.preview_heading', [
                'document' => static::pdfPreviewTitle($record, $type),
            ]))
            // No submit and no cancel: there is nothing to confirm, and a
            // slide-over with a stray "Save" under a read-only document reads
            // as though the preview edits something.
            ->modalSubmitAction(false)
            ->modalCancelAction(false)
            ->modalContent(fn (Model $record): View => view(
                'laravel-crm-filament::components.pdf-preview',
                [
                    'src' => DocumentPreview::urlFor($record),
                    'title' => static::pdfPreviewTitle($record, $type),
                ],
            ))
            // Hidden rather than broken when the panel does not carry this
            // plugin's routes — the same guard PortalUrl::exists() applies to
            // the portal links.
            ->visible(fn (): bool => DocumentPreview::available());
    }

    /**
     * The human-facing document number, falling back to the doc type when a
     * record has not been assigned one yet.
     */
    protected static function pdfPreviewTitle(Model $record, string $type): string
    {
        $attribute = CrmPdf::NUMBER_ATTRIBUTES[$type] ?? 'id';

        return (string) ($record->{$attribute} ?? $record->external_id ?? $type);
    }
}
