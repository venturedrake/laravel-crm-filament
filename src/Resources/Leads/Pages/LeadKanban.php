<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Leads\Pages;

use BackedEnum;
use Filament\Actions;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrmFilament\Concerns\AuthorizesKanbanBoard;
use VentureDrake\LaravelCrmFilament\Concerns\FiltersBoardByOwner;
use VentureDrake\LaravelCrmFilament\Concerns\PagesPipelineBoard;
use VentureDrake\LaravelCrmFilament\Resources\Deals\DealResource;
use VentureDrake\LaravelCrmFilament\Resources\Leads\LeadResource;

class LeadKanban extends Page
{
    use AuthorizesKanbanBoard;
    use FiltersBoardByOwner;
    use PagesPipelineBoard;

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

    protected function boardModel(): string
    {
        return Lead::class;
    }

    protected function boardScope(Builder $query): void
    {
        $query->whereNull($this->boardColumn('converted_at'));
    }

    /**
     * The cards on screen, keyed by stage id.
     *
     * @return array<int, Collection<int, Model>>
     */
    public function getLeadsByStage(): array
    {
        return $this->cardsByStage();
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

        // Core 2.7.0: only a stage on this board, never another pipeline's.
        $stage = $this->boardStage($stageId);

        DB::transaction(function () use ($lead, $stage): void {
            $this->placeInStage($lead, $stage);
            $lead->save();
        });
    }
}
