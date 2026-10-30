<?php

namespace VentureDrake\LaravelCrmFilament\Widgets;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Filament\Widgets\ChartWidget;
use VentureDrake\LaravelCrm\Models\Deal;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrmFilament\Concerns\HasChartRangeFilter;

class LeadsVsDealsChart extends ChartWidget
{
    use HasChartRangeFilter;

    protected static ?int $sort = 6;

    protected int | string | array $columnSpan = 1;

    public ?string $filter = 'last_30_days';

    public function getHeading(): ?string
    {
        return __('laravel-crm-filament::labels.dashboard.leads_vs_deals');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        [$start, $end, $bucket, $format] = $this->chartRange();

        $buckets = [];
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $buckets[$cursor->copy()->getTimestamp()] = [
                'label' => $cursor->format($format),
                'leads' => 0,
                'deals' => 0,
            ];
            $cursor = $this->advanceCursor($cursor, $bucket);
        }

        $leads = Lead::query()
            ->whereBetween('created_at', [$start, $end])
            ->get(['created_at']);

        foreach ($leads as $lead) {
            $key = $this->bucketKey(Carbon::parse($lead->created_at), $start, $bucket);

            if ($key !== null && isset($buckets[$key])) {
                $buckets[$key]['leads']++;
            }
        }

        $deals = Deal::query()
            ->whereBetween('created_at', [$start, $end])
            ->get(['created_at']);

        foreach ($deals as $deal) {
            $key = $this->bucketKey(Carbon::parse($deal->created_at), $start, $bucket);

            if ($key !== null && isset($buckets[$key])) {
                $buckets[$key]['deals']++;
            }
        }

        $labels = [];
        $leadSeries = [];
        $dealSeries = [];

        foreach ($buckets as $b) {
            $labels[] = $b['label'];
            $leadSeries[] = $b['leads'];
            $dealSeries[] = $b['deals'];
        }

        return [
            'datasets' => [
                [
                    'label' => __('laravel-crm-filament::labels.dashboard.new_leads'),
                    'data' => $leadSeries,
                    'backgroundColor' => '#05b3a9',
                ],
                [
                    'label' => __('laravel-crm-filament::labels.sales.deals'),
                    'data' => $dealSeries,
                    'backgroundColor' => '#6505B3',
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function allTimeStart(CarbonInterface $now): CarbonInterface
    {
        $earliestLead = Lead::query()->min('created_at');
        $earliestDeal = Deal::query()->min('created_at');

        $candidates = array_filter([$earliestLead, $earliestDeal]);

        if (empty($candidates)) {
            return $now->copy()->subMonth();
        }

        return collect($candidates)
            ->map(fn ($ts) => Carbon::parse($ts))
            ->sort()
            ->first();
    }
}
