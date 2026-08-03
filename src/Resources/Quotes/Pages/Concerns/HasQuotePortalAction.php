<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\Concerns;

use Filament\Actions\Action;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrmFilament\Support\PortalUrl;

trait HasQuotePortalAction
{
    protected function quotePortalAction(): Action
    {
        return Action::make('previewPortal')
            ->label(__('laravel-crm-filament::labels.actions.preview_portal'))
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->color('primary')
            // The quote portal route ships with base's routes.php, which is
            // unloaded entirely when laravel-crm.user_interface is off.
            ->visible(fn (): bool => PortalUrl::exists('laravel-crm.portal.quotes.show'))
            ->url(fn (Quote $record): ?string => PortalUrl::for('laravel-crm.portal.quotes.show', $record))
            ->openUrlInNewTab();
    }
}
