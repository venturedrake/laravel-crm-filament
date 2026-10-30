<?php

namespace VentureDrake\LaravelCrmFilament\Widgets;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Filament\Widgets\ChartWidget;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrmFilament\Concerns\HasChartRangeFilter;

class MonthlyRevenueChart extends ChartWidget
{
    use HasChartRangeFilter;

    protected static ?int $sort = 4;

    protected int | string | array $columnSpan = 1;

    public ?string $filter = 'last_30_days';

    public function getHeading(): ?string
    {
        return __('laravel-crm-filament::labels.dashboard.revenue_trend');
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        [$start, $end, $bucket, $format] = $this->chartRange();

        $buckets = [];
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $buckets[$cursor->copy()->getTimestamp()] = [
                'label' => $cursor->format($format),
                'invoices' => 0,
                'orders' => 0,
            ];
            $cursor = $this->advanceCursor($cursor, $bucket);
        }

        $paidInvoices = Invoice::query()
            ->whereNotNull('fully_paid_at')
            ->whereBetween('fully_paid_at', [$start, $end])
            ->get(['fully_paid_at', 'total']);

        foreach ($paidInvoices as $invoice) {
            $key = $this->bucketKey(Carbon::parse($invoice->fully_paid_at), $start, $bucket);

            if ($key !== null && isset($buckets[$key])) {
                $buckets[$key]['invoices'] += ((int) $invoice->total) / 100;
            }
        }

        $orders = Order::query()
            ->whereBetween('created_at', [$start, $end])
            ->get(['created_at', 'total']);

        foreach ($orders as $order) {
            $key = $this->bucketKey(Carbon::parse($order->created_at), $start, $bucket);

            if ($key !== null && isset($buckets[$key])) {
                $buckets[$key]['orders'] += ((int) $order->total) / 100;
            }
        }

        $labels = [];
        $invoiceSeries = [];
        $orderSeries = [];

        foreach ($buckets as $b) {
            $labels[] = $b['label'];
            $invoiceSeries[] = round($b['invoices'], 2);
            $orderSeries[] = round($b['orders'], 2);
        }

        return [
            'datasets' => [
                [
                    'label' => __('laravel-crm-filament::labels.dashboard.paid_invoices'),
                    'data' => $invoiceSeries,
                    'borderColor' => '#05b3a9',
                    'backgroundColor' => 'rgba(5,179,169,0.2)',
                    'fill' => false,
                    'tension' => 0.3,
                ],
                [
                    'label' => __('laravel-crm-filament::labels.dashboard.orders'),
                    'data' => $orderSeries,
                    'borderColor' => '#6505B3',
                    'backgroundColor' => 'rgba(101,5,179,0.2)',
                    'fill' => false,
                    'tension' => 0.3,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function allTimeStart(CarbonInterface $now): CarbonInterface
    {
        $earliestInvoice = Invoice::query()
            ->whereNotNull('fully_paid_at')
            ->min('fully_paid_at');

        $earliestOrder = Order::query()->min('created_at');

        $candidates = array_filter([$earliestInvoice, $earliestOrder]);

        if (empty($candidates)) {
            return $now->copy()->subMonth();
        }

        $earliest = collect($candidates)
            ->map(fn ($ts) => Carbon::parse($ts))
            ->sort()
            ->first();

        return $earliest;
    }
}
