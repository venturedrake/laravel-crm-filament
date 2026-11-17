<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\Concerns;

use Filament\Actions\Action;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrmFilament\Concerns\DownloadsPdf;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\InvoiceResource;

trait HasInvoiceSendAction
{
    use DownloadsPdf;

    /**
     * The resource's Send action, so the page header and the table row share
     * one implementation (recipients, failure handling, the sent flag).
     */
    protected function invoiceSendAction(): Action
    {
        return InvoiceResource::sendInvoiceActionFactory()
            ->label(__('laravel-crm-filament::labels.actions.send_invoice'))
            ->color('primary');
    }

    protected function invoiceDownloadPdfAction(): Action
    {
        return $this->downloadPdfAction(
            fn (Invoice $record) => $this->streamPdfDownload($record, 'invoice'),
        );
    }
}
