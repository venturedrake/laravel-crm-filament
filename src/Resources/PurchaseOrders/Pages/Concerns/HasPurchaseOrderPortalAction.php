<?php

namespace VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\Pages\Concerns;

use Filament\Actions\Action;
use VentureDrake\LaravelCrm\Models\PurchaseOrder;
use VentureDrake\LaravelCrmFilament\Support\PortalUrl;

trait HasPurchaseOrderPortalAction
{
    protected function purchaseOrderPortalAction(): Action
    {
        return Action::make('previewPortal')
            ->label(__('laravel-crm-filament::labels.actions.preview_portal'))
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->color('primary')
            ->visible(fn (PurchaseOrder $record): bool => $record->external_id !== null && $record->purchaseOrderLines()->count() > 0)
            ->url(fn (PurchaseOrder $record): ?string => PortalUrl::for('laravel-crm.portal.purchase-orders.show', $record))
            ->openUrlInNewTab();
    }
}
