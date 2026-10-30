<?php

namespace VentureDrake\LaravelCrmFilament\Widgets;

use Filament\Widgets\ChartWidget;
use VentureDrake\LaravelCrm\Models\Deal;
use VentureDrake\LaravelCrm\Models\Pipeline;
use VentureDrake\LaravelCrm\Models\PipelineStage;

class DealsPipelineValueChart extends ChartWidget
{
    protected static ?int $sort = 5;

    protected int | string | array $columnSpan = 1;

    public function getHeading(): ?string
    {
        return __('laravel-crm-filament::labels.dashboard.pipeline_by_stage_deals');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * Timeless chart per AC — no period filter dropdown.
     */
    protected function getFilters(): ?array
    {
        return null;
    }

    protected function getData(): array
    {
        // Deal pipelines only — every module (quotes, orders, invoices…) has
        // its own pipeline, and their stages would otherwise flood the axis.
        $stages = PipelineStage::query()
            ->whereIn('pipeline_id', Pipeline::query()->where('model', Deal::class)->select('id'))
            ->orderBy('order')
            ->get();

        $values = $stages->map(function ($stage) {
            $sumCents = (int) Deal::query()
                ->where('pipeline_stage_id', $stage->id)
                ->whereNull('closed_status')
                ->sum('amount');

            return $sumCents / 100;
        })->all();

        return [
            'datasets' => [
                [
                    'label' => __('laravel-crm-filament::labels.dashboard.pipeline_value'),
                    'data' => $values,
                    'backgroundColor' => '#05b3a9',
                ],
            ],
            'labels' => $stages->pluck('name')->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
        ];
    }
}
