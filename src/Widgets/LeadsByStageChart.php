<?php

namespace VentureDrake\LaravelCrmFilament\Widgets;

use Filament\Widgets\ChartWidget;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\Pipeline;
use VentureDrake\LaravelCrm\Models\PipelineStage;

class LeadsByStageChart extends ChartWidget
{
    protected static ?int $sort = 8;

    protected ?string $heading = 'Leads by pipeline stage';

    // Sorts after the two chart pairs, so a half-width span would leave it
    // stranded alone on its row.
    protected int | string | array $columnSpan = 'full';

    protected ?string $maxHeight = '300px';

    protected function getData(): array
    {
        // Lead pipelines only — every module (deals, quotes, orders…) has
        // its own pipeline, and their stages would otherwise flood the axis.
        $stages = PipelineStage::query()
            ->whereIn('pipeline_id', Pipeline::query()->where('model', Lead::class)->select('id'))
            ->orderBy('order')
            ->get();

        $counts = Lead::query()
            ->whereNull('converted_at')
            ->whereIn('pipeline_stage_id', $stages->pluck('id'))
            ->selectRaw('pipeline_stage_id, COUNT(*) as total')
            ->groupBy('pipeline_stage_id')
            ->pluck('total', 'pipeline_stage_id');

        return [
            'datasets' => [
                [
                    'label' => 'Open leads',
                    'data' => $stages->map(fn ($s) => (int) ($counts[$s->id] ?? 0))->all(),
                    'backgroundColor' => '#05b3a9',
                ],
            ],
            'labels' => $stages->pluck('name')->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
