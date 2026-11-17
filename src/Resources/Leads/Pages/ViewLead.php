<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Leads\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use VentureDrake\LaravelCrm\Models\Deal;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrmFilament\Concerns\HasCrmSideBySideRelationManagers;
use VentureDrake\LaravelCrmFilament\LaravelCrmPlugin;
use VentureDrake\LaravelCrmFilament\Resources\Deals\DealResource;
use VentureDrake\LaravelCrmFilament\Resources\Leads\LeadResource;

class ViewLead extends ViewRecord
{
    use HasCrmSideBySideRelationManagers;

    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            LeadResource::backToIndexAction(),
            LeadResource::convertAction()
                ->label(__('laravel-crm-filament::labels.actions.convert')),
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

    public function getSubheading(): string | Htmlable | null
    {
        /** @var Lead|null $lead */
        $lead = $this->record;

        $parts = array_filter([
            $this->stageBadge($lead),
            $this->convertedBadge($lead),
            $this->dealLink($lead),
        ]);

        if ($parts === []) {
            return null;
        }

        return new HtmlString(
            '<span class="inline-flex flex-wrap items-center gap-2">' . implode('', $parts) . '</span>'
        );
    }

    protected function stageBadge(?Lead $lead): ?string
    {
        $stage = $lead?->getRelationValue('pipelineStage')?->name;

        if (! $stage) {
            return null;
        }

        return '<span class="inline-flex items-center rounded-md bg-gray-900 px-3 py-1 text-sm font-medium text-white dark:bg-gray-100 dark:text-gray-900">'
            . e($stage)
            . '</span>';
    }

    /**
     * "Converted {date}" in place of the Convert button, dated with the
     * date_format setting, as core 2.7.0's lead page shows it.
     */
    protected function convertedBadge(?Lead $lead): ?string
    {
        if (! $lead?->isConverted()) {
            return null;
        }

        $format = app('laravel-crm.settings')->get('date_format', config('laravel-crm.date_format', 'Y-m-d'));

        return Blade::render(
            '<x-filament::badge color="success" data-crm-converted-badge>{{ $label }}</x-filament::badge>',
            ['label' => ucfirst(__('laravel-crm::lang.converted')) . ' ' . $lead->getAttribute('converted_at')->format($format)],
        );
    }

    /**
     * The deal the lead became, linked only for a user who may view it.
     * Deals the panel converted before 2.7.0 carry no lead_id, so their
     * lead shows the badge alone.
     */
    protected function dealLink(?Lead $lead): ?string
    {
        if (! $lead?->isConverted() || ! LaravelCrmPlugin::get()->isModuleEnabled('deals')) {
            return null;
        }

        $deal = $lead->getRelationValue('deal');

        if (! $deal instanceof Deal || ! DealResource::canView($deal)) {
            return null;
        }

        return Blade::render(
            '<x-filament::link :href="$url" icon="heroicon-m-arrow-top-right-on-square" icon-position="after">{{ $label }}</x-filament::link>',
            [
                'url' => DealResource::getUrl('view', ['record' => $deal]),
                'label' => ucfirst(__('laravel-crm::lang.deal')) . ' ' . $deal->getAttribute('deal_id'),
            ],
        );
    }
}
