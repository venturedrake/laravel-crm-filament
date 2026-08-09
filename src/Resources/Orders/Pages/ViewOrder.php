<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Orders\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrmFilament\Concerns\DownloadsPdf;
use VentureDrake\LaravelCrmFilament\Concerns\HasCrmSideBySideRelationManagers;
use VentureDrake\LaravelCrmFilament\Resources\Orders\OrderResource;

class ViewOrder extends ViewRecord
{
    use Concerns\HasOrderConvertToDeliveryAction;
    use Concerns\HasOrderConvertToInvoiceAction;
    use Concerns\HasOrderConvertToPurchaseOrderAction;
    use DownloadsPdf;
    use HasCrmSideBySideRelationManagers;

    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            OrderResource::backToIndexAction(),
            $this->orderConvertToInvoiceAction()
                ->label(__('laravel-crm-filament::labels.actions.invoice')),
            $this->orderConvertToDeliveryAction()
                ->label(__('laravel-crm-filament::labels.actions.delivery')),
            $this->orderConvertToPurchaseOrderAction()
                ->label(__('laravel-crm-filament::labels.actions.purchase_order')),
            $this->downloadPdfAction(fn (Order $record) => $this->streamPdfDownload($record, 'order'))
                ->button()
                ->hiddenLabel()
                ->icon('heroicon-m-arrow-down-tray'),
            Actions\EditAction::make()
                ->button()
                ->hiddenLabel()
                ->icon('heroicon-m-pencil-square'),
            Actions\DeleteAction::make()
                ->button()
                ->hiddenLabel()
                ->icon('heroicon-m-trash'),
        ];
    }

    public function getTitle(): string | Htmlable
    {
        return $this->record?->title ?? parent::getTitle();
    }
}
