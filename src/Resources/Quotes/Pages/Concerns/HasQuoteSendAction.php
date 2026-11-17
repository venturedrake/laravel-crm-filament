<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\Concerns;

use Filament\Actions\Action;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrmFilament\Concerns\DownloadsPdf;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\QuoteResource;

trait HasQuoteSendAction
{
    use DownloadsPdf;

    /**
     * The table's Send action ({@see QuoteResource::sendActionFactory()}),
     * so the View and Edit headers send, validate and mark sent the same way.
     */
    protected function quoteSendAction(): Action
    {
        return QuoteResource::sendActionFactory()
            ->label(__('laravel-crm-filament::labels.actions.send_quote'))
            ->color('primary');
    }

    protected function quoteDownloadPdfAction(): Action
    {
        return $this->downloadPdfAction(
            fn (Quote $record) => $this->streamPdfDownload($record, 'quote'),
        );
    }
}
