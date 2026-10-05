<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Leads\Pages;

use BackedEnum;
use Filament\Actions;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\Pipeline;
use VentureDrake\LaravelCrm\Models\PipelineStage;
use VentureDrake\LaravelCrmFilament\Concerns\AuthorizesKanbanBoard;
use VentureDrake\LaravelCrmFilament\Concerns\FiltersBoardByOwner;
use VentureDrake\LaravelCrmFilament\Resources\Deals\DealResource;
use VentureDrake\LaravelCrmFilament\Resources\Leads\LeadResource;

class LeadKanban extends Page
{
    use AuthorizesKanbanBoard;
    use FiltersBoardByOwner;

    protected static string $resource = LeadResource::class;

    protected string $view = 'laravel-crm-filament::leads.kanban';

    protected static ?string $title = 'Lead pipeline';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-view-columns';

    protected function getHeaderActions(): array
    {
        return [
            ...LeadResource::listKanbanToggleActions('kanban'),
            Actions\CreateAction::make()
                ->url(LeadResource::getUrl('create')),
        ];
    }

    public function getStages(): Collection
    {
        $pipelineIds = Pipeline::query()
            ->where('model', Lead::class)
            ->pluck('id');

        return PipelineStage::query()
            ->whereIn('pipeline_id', $pipelineIds)
            ->orderBy('order')
            ->get();
    }

    public function getLeadsByStage(): array
    {
        $leads = Lead::query()
            ->whereNull('converted_at')
            ->when($this->ownerFilter, fn ($q) => $q->where('user_owner_id', $this->ownerFilter))
            ->whereNotNull('pipeline_stage_id')
            ->orderByDesc('updated_at')
            ->get();

        return $leads->groupBy('pipeline_stage_id')->all();
    }

    public function convertToDeal(string $externalId): void
    {
        $lead = Lead::query()->where('external_id', $externalId)->first();
        if (! $lead) {
            return;
        }

        // Both abilities, because the conversion exercises both: it writes the
        // lead (marking it converted) and creates a Deal. Edit rights on a lead
        // are not permission to create deals.
        $this->authorizeKanbanEdit($lead);
        $this->authorizeKanbanCreate(DealResource::class);

        LeadResource::doConvertToDeal($lead);
    }

    public function moveLead(string $externalId, ?int $stageId): void
    {
        $lead = Lead::query()->where('external_id', $externalId)->first();
        if (! $lead) {
            return;
        }

        $this->authorizeKanbanEdit($lead);

        $lead->pipeline_stage_id = $stageId;
        if ($stageId) {
            $stage = PipelineStage::find($stageId);
            $lead->pipeline_id = $stage?->pipeline_id;
        }
        $lead->save();
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
