<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\Concerns;

use Filament\Actions\Action;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrmFilament\Support\PortalUrl;

trait HasOrderPortalAction
{
    protected function orderPortalAction(): Action
    {
        return Action::make('previewPortal')
            ->label(__('laravel-crm-filament::labels.actions.preview_portal'))
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->color('primary')
            ->visible(fn (Order $record): bool => PortalUrl::exists('laravel-crm.portal.orders.show')
                && $record->external_id !== null
                && $record->orderProducts()->count() > 0)
            ->url(fn (Order $record): ?string => PortalUrl::for('laravel-crm.portal.orders.show', $record))
            ->openUrlInNewTab();
    }
}
