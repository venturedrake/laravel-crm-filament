<?php

namespace VentureDrake\LaravelCrmFilament\Resources\EmailCampaigns\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use VentureDrake\LaravelCrm\Models\EmailCampaign;
use VentureDrake\LaravelCrmFilament\Resources\EmailCampaigns\EmailCampaignResource;

class ListEmailCampaigns extends ListRecords
{
    protected static string $resource = EmailCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }

    public function getTabs(): array
    {
        $statusTab = fn (string $label, string $status, ?string $color = null) => Tab::make($label)
            ->modifyQueryUsing(fn (Builder $query) => $query->where('status', $status))
            ->badge(fn () => EmailCampaign::query()->where('status', $status)->count() ?: null)
            ->badgeColor($color);

        return [
            'all' => Tab::make('All'),
            'draft' => $statusTab('Draft', 'draft'),
            'scheduled' => $statusTab('Scheduled', 'scheduled', 'warning'),
            'sending' => $statusTab('Sending', 'sending', 'info'),
            'sent' => $statusTab('Sent', 'sent', 'success'),
            'failed' => $statusTab('Failed', 'failed', 'danger'),
        ];
    }
}
