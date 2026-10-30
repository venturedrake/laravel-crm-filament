<?php

namespace VentureDrake\LaravelCrmFilament\Widgets;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Filament\Widgets\ChartWidget;
use VentureDrake\LaravelCrm\Models\Deal;
use VentureDrake\LaravelCrmFilament\Concerns\HasChartRangeFilter;

class DealStatusDoughnutChart extends ChartWidget
{
    use HasChartRangeFilter;

    protected static ?int $sort = 7;

    protected int | string | array $columnSpan = ['default' => 1, 'md' => 1, 'xl' => 1];

    public ?string $filter = 'last_30_days';

    public function getHeading(): ?string
    {
        return __('laravel-crm-filament::labels.dashboard.deal_status_distribution');
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        [$start, $end] = $this->chartRange();

        $open = Deal::query()
            ->whereBetween('created_at', [$start, $end])
            ->whereNull('closed_status')
            ->count();

        $won = Deal::query()
            ->where('closed_status', 'won')
            ->whereBetween('closed_at', [$start, $end])
            ->count();

        $lost = Deal::query()
            ->where('closed_status', 'lost')
            ->whereBetween('closed_at', [$start, $end])
            ->count();

        return [
            'datasets' => [
                [
                    'label' => __('laravel-crm-filament::labels.dashboard.deal_status_distribution'),
                    'data' => [$open, $won, $lost],
                    'backgroundColor' => ['#05b3a9', '#22c55e', '#ef4444'],
                ],
            ],
            'labels' => [
                __('laravel-crm-filament::labels.dashboard.status_open'),
                __('laravel-crm-filament::labels.dashboard.status_won'),
                __('laravel-crm-filament::labels.dashboard.status_lost'),
            ],
        ];
    }

    protected function allTimeStart(CarbonInterface $now): CarbonInterface
    {
        $earliest = Deal::query()->min('created_at');

        if ($earliest === null) {
            return $now->copy()->subMonth();
        }

        return Carbon::parse($earliest);
    }
}
