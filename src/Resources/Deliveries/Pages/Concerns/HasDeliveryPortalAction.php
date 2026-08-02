<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Deliveries\Pages\Concerns;

use Filament\Actions\Action;
use VentureDrake\LaravelCrm\Models\Delivery;
use VentureDrake\LaravelCrmFilament\Support\PortalUrl;

trait HasDeliveryPortalAction
{
    protected function deliveryPortalAction(): Action
    {
        return Action::make('previewPortal')
            ->label(__('laravel-crm-filament::labels.actions.preview_portal'))
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->color('primary')
            ->visible(fn (Delivery $record): bool => PortalUrl::exists('laravel-crm.portal.deliveries.show')
                && $record->external_id !== null
                && $record->deliveryProducts()->count() > 0)
            ->url(fn (Delivery $record): ?string => PortalUrl::for('laravel-crm.portal.deliveries.show', $record))
            ->openUrlInNewTab();
    }
}
