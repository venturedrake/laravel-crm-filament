<?php

namespace VentureDrake\LaravelCrmFilament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use VentureDrake\LaravelCrm\Models\Deal;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrmFilament\LaravelCrmPlugin;
use VentureDrake\LaravelCrmFilament\Support\MoneyForm;

class CrmStatsOverview extends StatsOverviewWidget
{
    protected ?string $heading = null;

    public function getHeading(): ?string
    {
        return __('laravel-crm-filament::labels.dashboard.heading_sales')
            . ' ' . __('laravel-crm-filament::labels.dashboard.heading_period_suffix', [
                'period' => __('laravel-crm-filament::labels.sales.last_30_days'),
            ]);
    }

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $stats = [];
        $windowStart = now()->subDays(30)->startOfDay();
        $windowEnd = now();

        if ($this->moduleEnabled('leads')) {
            $newLeads = Lead::query()
                ->whereBetween('created_at', [$windowStart, $windowEnd])
                ->count();

            $converted = Lead::query()
                ->whereBetween('created_at', [$windowStart, $windowEnd])
                ->whereNotNull('converted_at')
                ->count();

            $rate = $newLeads > 0 ? (int) round(($converted / $newLeads) * 100) : 0;

            $stats[] = Stat::make(
                __('laravel-crm-filament::labels.dashboard.new_leads'),
                (string) $newLeads,
            )->description(__('laravel-crm-filament::labels.dashboard.convert_rate', ['rate' => $rate]))
                ->color('primary');
        }

        if ($this->moduleEnabled('deals')) {
            $pipelineValueCents = (int) Deal::query()
                ->whereNull('closed_at')
                ->sum('amount');
            $openDealsCount = Deal::query()->whereNull('closed_at')->count();

            $stats[] = Stat::make(
                __('laravel-crm-filament::labels.dashboard.pipeline_value'),
                $this->formatMoney($pipelineValueCents),
            )->description(__('laravel-crm-filament::labels.dashboard.open_deals_count', ['count' => $openDealsCount]))
                ->color('warning');

            $wonCount = Deal::query()
                ->where('closed_status', 'won')
                ->whereBetween('closed_at', [$windowStart, $windowEnd])
                ->count();

            $wonValueCents = (int) Deal::query()
                ->where('closed_status', 'won')
                ->whereBetween('closed_at', [$windowStart, $windowEnd])
                ->sum('amount');

            $stats[] = Stat::make(
                __('laravel-crm-filament::labels.dashboard.deals_won'),
                (string) $wonCount,
            )->description($this->formatMoney($wonValueCents))
                ->color('success');
        }

        return $stats;
    }

    protected function moduleEnabled(string $module): bool
    {
        try {
            return LaravelCrmPlugin::get()->isModuleEnabled($module);
        } catch (\Throwable $e) {
            return in_array($module, (array) config('laravel-crm.modules', []), true);
        }
    }

    protected function formatMoney(int $cents): string
    {
        // display() owns the number_format fallback for when the money helper
        // cannot resolve the currency, so there is no guard needed here.
        return (string) MoneyForm::display($cents, config('laravel-crm.default_currency', 'USD'));
    }
}
