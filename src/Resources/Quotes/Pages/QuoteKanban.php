<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages;

use BackedEnum;
use Filament\Actions;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;
use VentureDrake\LaravelCrm\Models\Pipeline;
use VentureDrake\LaravelCrm\Models\PipelineStage;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\QuoteResource;

class QuoteKanban extends Page
{
    protected static string $resource = QuoteResource::class;

    protected string $view = 'laravel-crm-filament::quotes.kanban';

    protected static ?string $title = 'Quote pipeline';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-view-columns';

    public ?int $ownerFilter = null;

    protected function getHeaderActions(): array
    {
        return [
            ...QuoteResource::listKanbanToggleActions('kanban'),
            Actions\CreateAction::make()
                ->url(QuoteResource::getUrl('create')),
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
            ->where('model', Quote::class)
            ->pluck('id');

        return PipelineStage::query()
            ->whereIn('pipeline_id', $pipelineIds)
            ->orderBy('order')
            ->get();
    }

    public function getQuotesByStage(): array
    {
        $quotes = Quote::query()
            ->whereNull('accepted_at')
            ->whereNull('rejected_at')
            ->when($this->ownerFilter, fn ($q) => $q->where('user_owner_id', $this->ownerFilter))
            ->whereNotNull('pipeline_stage_id')
            ->orderByDesc('updated_at')
            ->get();

        return $quotes->groupBy('pipeline_stage_id')->all();
    }

    public function markAccepted(string $externalId): void
    {
        $this->closeQuote($externalId, true);
    }

    public function markRejected(string $externalId): void
    {
        $this->closeQuote($externalId, false);
    }

    protected function closeQuote(string $externalId, bool $accepted): void
    {
        $quote = Quote::query()->where('external_id', $externalId)->first();
        if (! $quote) {
            return;
        }
        if ($accepted) {
            $quote->accepted_at = now();
        } else {
            $quote->rejected_at = now();
        }
        $quote->save();
    }

    public function moveQuote(string $externalId, ?int $stageId): void
    {
        $quote = Quote::query()->where('external_id', $externalId)->first();
        if (! $quote) {
            return;
        }
        $quote->pipeline_stage_id = $stageId;
        if ($stageId) {
            $stage = PipelineStage::find($stageId);
            $quote->pipeline_id = $stage?->pipeline_id;
        }
        $quote->save();
    }

    /**
     * The stage column total, in stored cents, ready for money().
     */
    public function getStageTotal(int $stageId, array $byStage): int
    {
        $rows = $byStage[$stageId] ?? collect();
        $sum = 0;
        foreach ($rows as $row) {
            $sum += (int) ($row->total ?? 0);
        }

        return $sum;
    }
}
