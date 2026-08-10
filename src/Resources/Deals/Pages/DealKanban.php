<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Deals\Pages;

use BackedEnum;
use Filament\Actions;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use VentureDrake\LaravelCrm\Models\Deal;
use VentureDrake\LaravelCrm\Models\Pipeline;
use VentureDrake\LaravelCrm\Models\PipelineStage;
use VentureDrake\LaravelCrmFilament\Resources\Deals\DealResource;

class DealKanban extends Page
{
    protected static string $resource = DealResource::class;

    protected string $view = 'laravel-crm-filament::deals.kanban';

    protected static ?string $title = 'Deal pipeline';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-view-columns';

    public ?int $ownerFilter = null;

    protected function getHeaderActions(): array
    {
        return [
            ...DealResource::listKanbanToggleActions('kanban'),
            Actions\CreateAction::make()
                ->url(DealResource::getUrl('create')),
        ];
    }

    public function getOwners(): Collection
    {
        $userClass = config('auth.providers.users.model');

        return $userClass::query()->orderBy('name')->pluck('name', 'id');
    }

    public function getStages(): Collection
    {
        $pipelineIds = Pipeline::query()
            ->where('model', Deal::class)
            ->pluck('id');

        return PipelineStage::query()
            ->whereIn('pipeline_id', $pipelineIds)
            ->orderBy('order')
            ->get();
    }

    public function getDealsByStage(): array
    {
        $deals = Deal::query()
            ->whereNull('closed_at')
            ->when($this->ownerFilter, fn ($q) => $q->where('user_owner_id', $this->ownerFilter))
            ->whereNotNull('pipeline_stage_id')
            ->orderByDesc('updated_at')
            ->get();

        return $deals->groupBy('pipeline_stage_id')->all();
    }

    public function markWon(string $externalId): void
    {
        $this->closeDeal($externalId, true);
    }

    public function markLost(string $externalId): void
    {
        $this->closeDeal($externalId, false);
    }

    protected function closeDeal(string $externalId, bool $won): void
    {
        $deal = Deal::query()->where('external_id', $externalId)->first();
        if (! $deal) {
            return;
        }
        $deal->forceFill([
            'closed_at' => now(),
            'closed_status' => $won ? 'won' : 'lost',
        ]);
        if (Schema::hasColumn($deal->getTable(), 'won')) {
            $deal->won = $won;
        }
        $deal->save();
    }

    public function moveDeal(string $externalId, ?int $stageId): void
    {
        $deal = Deal::query()->where('external_id', $externalId)->first();
        if (! $deal) {
            return;
        }
        $deal->pipeline_stage_id = $stageId;
        if ($stageId) {
            $stage = PipelineStage::find($stageId);
            $deal->pipeline_id = $stage?->pipeline_id;
        }
        $deal->save();
    }

    /**
     * The stage column total, in stored cents, ready for money().
     */
    public function getStageTotal(int $stageId, array $byStage): int
    {
        $rows = $byStage[$stageId] ?? collect();
        $sum = 0;
        foreach ($rows as $row) {
            $sum += (int) ($row->amount ?? 0);
        }

        return $sum;
    }
}
