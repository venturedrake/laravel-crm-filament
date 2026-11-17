<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages;

use BackedEnum;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrm\Services\QuoteStateService;
use VentureDrake\LaravelCrmFilament\Concerns\AuthorizesKanbanBoard;
use VentureDrake\LaravelCrmFilament\Concerns\FiltersBoardByOwner;
use VentureDrake\LaravelCrmFilament\Concerns\PagesPipelineBoard;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\QuoteResource;

class QuoteKanban extends Page
{
    use AuthorizesKanbanBoard;
    use FiltersBoardByOwner;
    use PagesPipelineBoard;

    protected static string $resource = QuoteResource::class;

    protected string $view = 'laravel-crm-filament::quotes.kanban';

    protected static ?string $title = 'Quote pipeline';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-view-columns';

    protected function getHeaderActions(): array
    {
        return [
            ...QuoteResource::listKanbanToggleActions('kanban'),
            Actions\CreateAction::make()
                ->url(QuoteResource::getUrl('create')),
        ];
    }

    protected function boardModel(): string
    {
        return Quote::class;
    }

    protected function boardScope(Builder $query): void
    {
        $query->whereNull($this->boardColumn('accepted_at'))
            ->whereNull($this->boardColumn('rejected_at'));
    }

    protected function amountColumn(): string
    {
        return 'total';
    }

    /**
     * The cards on screen, keyed by stage id.
     *
     * @return array<int, Collection<int, Model>>
     */
    public function getQuotesByStage(): array
    {
        return $this->cardsByStage();
    }

    public function markAccepted(string $externalId): void
    {
        $this->closeQuote($externalId, true);
    }

    public function markRejected(string $externalId): void
    {
        $this->closeQuote($externalId, false);
    }

    /**
     * The card's Accept / Reject buttons, which are core QuoteBoard's
     * accept()/reject() menu items: the service stamps the response and
     * moves the quote to the matching stage. Refused first for the reasons
     * a drag into that stage would be (core 2.7.0 hides those menu items
     * while the amounts are wrong).
     */
    protected function closeQuote(string $externalId, bool $accepted): void
    {
        $quote = Quote::query()->where('external_id', $externalId)->first();
        if (! $quote) {
            return;
        }

        $this->authorizeKanbanEdit($quote);

        if ($this->refusesMove($quote, $accepted ? QuoteStateService::ACCEPTED : QuoteStateService::REJECTED)) {
            return;
        }

        $service = app(QuoteStateService::class);
        $accepted ? $service->accept($quote) : $service->reject($quote);
    }

    public function moveQuote(string $externalId, ?int $stageId): void
    {
        $quote = Quote::query()->where('external_id', $externalId)->first();
        if (! $quote) {
            return;
        }

        $this->authorizeKanbanEdit($quote);

        // Core 2.7.0: only a stage on this board, never another pipeline's.
        $stage = $this->boardStage($stageId);

        // A drag within a column only reorders it, as core's onStageSorted():
        // nothing is checked or stamped. Otherwise nudging a quote that sits
        // in Accepted or Rejected without a response would record one.
        if ((int) $quote->pipeline_stage_id === (int) $stage->getKey()) {
            return;
        }

        if ($this->refusesMove($quote, $stage->getAttribute('name'))) {
            return;
        }

        // As core QuoteBoard::stageChanged(): the column moves, then the
        // response it stands for (Accepted / Rejected) is stamped.
        DB::transaction(function () use ($quote, $stage): void {
            $this->placeInStage($quote, $stage);
            $quote->save();

            app(QuoteStateService::class)->moved($quote, $stage->getAttribute('name'));
        });
    }

    /**
     * Core 2.7.0's QuoteStateService::moveBlockedReason(): a drag never
     * removes a response, and wrong amounts stop one being given. Nothing
     * is saved, and the re-render puts the card back.
     */
    protected function refusesMove(Quote $quote, ?string $to): bool
    {
        $reason = app(QuoteStateService::class)->moveBlockedReason($quote, $to);

        if ($reason === null) {
            return false;
        }

        Notification::make()
            ->danger()
            ->title($reason)
            ->send();

        return true;
    }

    /**
     * On the `update` policy, as core QuoteBoard authorizes, rather than
     * QuoteResource::canEdit(): the edit form refuses a locked quote, but the
     * board may still move one (an ordered quote can go to any column).
     */
    protected function authorizeKanbanEdit(Model $record): void
    {
        abort_unless(
            static::kanbanPolicyCheck(fn (): bool => QuoteResource::can('update', $record)),
            Response::HTTP_FORBIDDEN,
        );
    }
}
