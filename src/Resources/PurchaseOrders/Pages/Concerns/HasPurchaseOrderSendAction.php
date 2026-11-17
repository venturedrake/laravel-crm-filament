<?php

namespace VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\Pages\Concerns;

use Filament\Actions\Action;
use VentureDrake\LaravelCrm\Models\PurchaseOrder;
use VentureDrake\LaravelCrmFilament\Concerns\DownloadsPdf;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\PurchaseOrderResource;

trait HasPurchaseOrderSendAction
{
    use DownloadsPdf;

    /**
     * The table's Send action, so the page and the row cannot drift apart
     * (the page used to carry its own copy, which never set `sent`).
     */
    protected function purchaseOrderSendAction(): Action
    {
        return PurchaseOrderResource::sendPurchaseOrderActionFactory()
            ->label(__('laravel-crm-filament::labels.actions.send_purchase_order'))
            ->color('primary');
    }

    protected function purchaseOrderDownloadPdfAction(): Action
    {
        return $this->downloadPdfAction(
            fn (PurchaseOrder $record) => $this->streamPdfDownload($record, 'purchaseorder'),
        );
    }
}
