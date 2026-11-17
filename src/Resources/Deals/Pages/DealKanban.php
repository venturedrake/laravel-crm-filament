<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Deals\Pages;

use BackedEnum;
use Filament\Actions;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use VentureDrake\LaravelCrm\Models\Deal;
use VentureDrake\LaravelCrmFilament\Concerns\AuthorizesKanbanBoard;
use VentureDrake\LaravelCrmFilament\Concerns\FiltersBoardByOwner;
use VentureDrake\LaravelCrmFilament\Concerns\PagesPipelineBoard;
use VentureDrake\LaravelCrmFilament\Resources\Deals\DealResource;

class DealKanban extends Page
{
    use AuthorizesKanbanBoard;
    use FiltersBoardByOwner;
    use PagesPipelineBoard;

    protected static string $resource = DealResource::class;

    protected string $view = 'laravel-crm-filament::deals.kanban';

    protected static ?string $title = 'Deal pipeline';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-view-columns';

    protected function getHeaderActions(): array
    {
        return [
            ...DealResource::listKanbanToggleActions('kanban'),
            Actions\CreateAction::make()
                ->url(DealResource::getUrl('create')),
        ];
    }

    protected function boardModel(): string
    {
        return Deal::class;
    }

    protected function boardScope(Builder $query): void
    {
        $query->whereNull($this->boardColumn('closed_at'));
    }

    /**
     * The cards on screen, keyed by stage id.
     *
     * @return array<int, Collection<int, Model>>
     */
    public function getDealsByStage(): array
    {
        return $this->cardsByStage();
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

        $this->authorizeKanbanEdit($deal);
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

        $this->authorizeKanbanEdit($deal);

        // Core 2.7.0: only a stage on this board, never another pipeline's.
        $stage = $this->boardStage($stageId);

        DB::transaction(function () use ($deal, $stage): void {
            $this->placeInStage($deal, $stage);
            $deal->save();
        });
    }
}
