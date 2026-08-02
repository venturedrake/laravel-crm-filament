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
            ->url(fn (Quote $record): ?string => PortalUrl::for('laravel-crm.portal.quotes.show', $record))
            ->openUrlInNewTab();
    }
}
