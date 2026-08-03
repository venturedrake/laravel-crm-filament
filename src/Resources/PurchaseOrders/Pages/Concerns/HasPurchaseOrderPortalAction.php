<?php

namespace VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\Pages\Concerns;

use Filament\Actions\Action;
use VentureDrake\LaravelCrm\Models\PurchaseOrder;
use VentureDrake\LaravelCrmFilament\Support\PortalUrl;
use VentureDrake\LaravelCrmFilament\Support\PurchaseOrderPortalLink;

trait HasPurchaseOrderPortalAction
{
    protected function purchaseOrderPortalAction(): Action
    {
        return Action::make('previewPortal')
            ->label(__('laravel-crm-filament::labels.actions.preview_portal'))
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->color('primary')
            // The route check mirrors the Order and Delivery actions: base
            // registers no purchase-order portal route, and an action whose
            // url() resolves to null renders as an inert button.
            ->visible(fn (PurchaseOrder $record): bool => PurchaseOrderPortalLink::available()
                && $record->external_id !== null
                && $record->purchaseOrderLines()->count() > 0)
            ->url(fn (PurchaseOrder $record): ?string => PortalUrl::for(PurchaseOrderPortalLink::ROUTE, $record))
            ->openUrlInNewTab();
    }
}
