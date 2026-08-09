<?php

namespace VentureDrake\LaravelCrmFilament\Concerns;

use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use VentureDrake\LaravelCrmFilament\Support\CrmPdf;

/**
 * Shared PDF generation + download trait for the document ViewRecord pages and
 * their Send concerns.
 *
 * The rendering itself lives in {@see CrmPdf}, which the Resources' static
 * row-action renderers also call — page header actions are instance-bound and
 * table row actions are static, so both entry points have to exist, but there
 * is only one implementation behind them.
 *
 * The $view/$data parameters these methods used to take are gone on purpose:
 * they were how ten hardcoded `laravel-crm::{x}.pdf` strings bypassed core
 * 2.4.0's template picker.
 */
trait DownloadsPdf
{
    /**
     * Render the record's PDF to disk and return the storage-path-relative
     * location (e.g. `app/laravel-crm/quote/42/quote-q1042.pdf`).
     */
    protected function renderPdfToDisk(Model $record, string $type): string
    {
        return CrmPdf::renderToDisk($record, $type);
    }

    /**
     * Stream the generated PDF as an HTTP download response.
     */
    protected function streamPdfDownload(Model $record, string $type): BinaryFileResponse
    {
        return CrmPdf::download($record, $type);
    }

    /**
     * Build the shared "Download PDF" header action. Each ViewRecord page wires
     * this to the appropriate generator via the $build callback (which should
     * return a streamed Response).
     */
    protected function downloadPdfAction(callable $build): Action
    {
        return Action::make('downloadPdf')
            ->label(__('laravel-crm-filament::labels.actions.download_pdf'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->action($build);
    }
}
