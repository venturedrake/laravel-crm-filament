<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\Concerns;

use Filament\Actions\Action;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrmFilament\Support\PortalUrl;

trait HasInvoicePortalAction
{
    protected function invoicePortalAction(): Action
    {
        return Action::make('previewPortal')
            ->label(__('laravel-crm-filament::labels.actions.preview_portal'))
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->color('primary')
            // The invoice portal route ships with base's routes.php, which is
            // unloaded entirely when laravel-crm.user_interface is off.
            ->visible(fn (): bool => PortalUrl::exists('laravel-crm.portal.invoices.show'))
            ->url(fn (Invoice $record): ?string => PortalUrl::for('laravel-crm.portal.invoices.show', $record))
            ->openUrlInNewTab();
    }
}
