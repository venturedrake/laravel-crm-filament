<?php

namespace VentureDrake\LaravelCrmFilament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrmFilament\LaravelCrmPlugin;
use VentureDrake\LaravelCrmFilament\Support\MoneyForm;

class DealsValueStat extends StatsOverviewWidget
{
    protected ?string $heading = null;

    public function getHeading(): ?string
    {
        return __('laravel-crm-filament::labels.dashboard.heading_finance')
            . ' ' . __('laravel-crm-filament::labels.dashboard.heading_period_suffix', [
                'period' => __('laravel-crm-filament::labels.sales.last_30_days'),
            ]);
    }

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $stats = [];
        $windowStart = now()->subDays(30)->startOfDay();
        $windowEnd = now();

        if ($this->moduleEnabled('invoices')) {
            $outstandingCents = (int) Invoice::query()
                ->whereNull('fully_paid_at')
                ->sum('amount_due');
            $unpaidCount = Invoice::query()->whereNull('fully_paid_at')->count();

            $stats[] = Stat::make(
                __('laravel-crm-filament::labels.dashboard.outstanding_invoices'),
                $this->formatMoney($outstandingCents),
            )->description(__('laravel-crm-filament::labels.dashboard.unpaid_count', ['count' => $unpaidCount]))
                ->color('warning');

            $paidCents = (int) Invoice::query()
                ->whereBetween('fully_paid_at', [$windowStart, $windowEnd])
                ->sum('total');
            $paidCount = Invoice::query()
                ->whereBetween('fully_paid_at', [$windowStart, $windowEnd])
                ->count();

            $stats[] = Stat::make(
                __('laravel-crm-filament::labels.dashboard.invoices_paid'),
                $this->formatMoney($paidCents),
            )->description(__('laravel-crm-filament::labels.dashboard.paid_count', ['count' => $paidCount]))
                ->color('success');
        }

        if ($this->moduleEnabled('quotes')) {
            $quotesCount = Quote::query()
                ->whereBetween('created_at', [$windowStart, $windowEnd])
                ->count();

            $stats[] = Stat::make(
                __('laravel-crm-filament::labels.dashboard.quotes_created'),
                (string) $quotesCount,
            )->color('primary');
        }

        if ($this->moduleEnabled('orders')) {
            $ordersCount = Order::query()
                ->whereBetween('created_at', [$windowStart, $windowEnd])
                ->count();

            $stats[] = Stat::make(
                __('laravel-crm-filament::labels.dashboard.orders_created'),
                (string) $ordersCount,
            )->color('primary');
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
