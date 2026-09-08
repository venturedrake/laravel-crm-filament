<?php

namespace VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use VentureDrake\LaravelCrmFilament\Concerns\HasCrmSideBySideRelationManagers;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\PurchaseOrderResource;
use VentureDrake\LaravelCrmFilament\Support\PurchaseOrderPortalLink;

class ViewPurchaseOrder extends ViewRecord
{
    use Concerns\HasPurchaseOrderPortalAction;
    use Concerns\HasPurchaseOrderSendAction;
    use HasCrmSideBySideRelationManagers;

    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PurchaseOrderResource::backToIndexAction(),
            $this->purchaseOrderSendAction()
                ->button()
                ->label(__('laravel-crm-filament::labels.actions.send'))
                ->color('gray'),
            $this->purchaseOrderDownloadPdfAction()
                ->button()
                ->hiddenLabel()
                ->icon('heroicon-m-arrow-down-tray'),
            PurchaseOrderResource::pdfPreviewAction('purchaseorder')
                ->button()
                ->hiddenLabel()
                ->icon('heroicon-m-document-magnifying-glass'),
            PurchaseOrderResource::portalLinkAction(PurchaseOrderPortalLink::ROUTE)
                ->button()
                ->hiddenLabel()
                ->icon('heroicon-m-link'),
            $this->purchaseOrderPortalAction()
                ->button()
                ->hiddenLabel()
                ->icon('heroicon-m-arrow-top-right-on-square')
                ->color('gray'),
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

    public function getHeading(): string | Htmlable
    {
        return $this->record?->title ?? parent::getHeading();
    }
}
