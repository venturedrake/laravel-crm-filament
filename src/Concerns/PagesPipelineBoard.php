<?php

namespace VentureDrake\LaravelCrmFilament\Concerns;

use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpFoundation\Response;
use VentureDrake\LaravelCrm\Models\Pipeline;
use VentureDrake\LaravelCrm\Models\PipelineStage;
use VentureDrake\LaravelCrmFilament\Support\MoneyForm;

/**
 * The lead, deal and quote boards: one pipeline's stages as columns, each
 * column paged.
 *
 * Mirrors core 2.7.0's `Livewire\PipelineBoard`. Rendering every record cost
 * tens of megabytes of HTML on a large install, so a column shows its first
 * `laravel-crm.board.per_column` cards and fetches more on demand. The
 * header count and per-currency totals come from one grouped query rather
 * than from the cards on screen, so they still describe the whole column.
 *
 * Only the first pipeline for the model is shown, the way core does: the
 * boards used to merge the stages of every pipeline into one row of columns.
 *
 * Uses {@see FiltersBoardByOwner}, whose owner filter narrows the cards, the
 * counts and the totals alike.
 *
 * @phpstan-require-extends Page
 */
trait PagesPipelineBoard
{
    /**
     * Cards on screen per stage id, past the first page. Locked so a client
     * cannot ask a column for every record at once.
     *
     * @var array<int|string, int>
     */
    #[Locked]
    public array $stageLimits = [];

    /**
     * @var Collection<int, PipelineStage>|null
     */
    protected ?Collection $boardStages = null;

    /**
     * The model class the board shows.
     *
     * @return class-string<Model>
     */
    abstract protected function boardModel(): string;

    /**
     * Narrow the records that belong on the board at all.
     */
    protected function boardScope(Builder $query): void
    {
        //
    }

    /**
     * The column the header totals sum.
     */
    protected function amountColumn(): string
    {
        return 'amount';
    }

    /**
     * The board's columns: the first pipeline for the model, its stages in
     * their set order (id settles a tie), as core 2.7.0 does.
     *
     * @return Collection<int, PipelineStage>
     */
    public function getStages(): Collection
    {
        if ($this->boardStages !== null) {
            return $this->boardStages;
        }

        $pipeline = Pipeline::query()
            ->where('model', $this->boardModel())
            ->orderBy('id')
            ->first();

        /** @var Collection<int, PipelineStage> $stages */
        $stages = $pipeline
            ? $pipeline->pipelineStages()->orderBy('order')->orderBy('id')->get()
            : collect();

        return $this->boardStages = $stages;
    }

    /**
     * A stage the board shows, or a 404. Stage ids arrive from the browser,
     * and a card dropped on anything else (another pipeline's stage, a
     * deleted one, none) would vanish from the board.
     */
    protected function boardStage(mixed $stageId): PipelineStage
    {
        $stage = is_numeric($stageId)
            ? $this->getStages()->first(fn (PipelineStage $stage): bool => (int) $stage->getKey() === (int) $stageId)
            : null;

        abort_unless($stage instanceof PipelineStage, Response::HTTP_NOT_FOUND);

        return $stage;
    }

    protected function boardTable(): string
    {
        $model = $this->boardModel();

        return (new $model)->getTable();
    }

    protected function boardColumn(string $column): string
    {
        return $this->boardTable() . '.' . $column;
    }

    /**
     * A qualified column for raw SQL, which gets no table prefix or quoting
     * from the query builder.
     */
    protected function rawBoardColumn(string $column): string
    {
        return $this->boardModel()::query()->getQuery()->getGrammar()->wrap($this->boardColumn($column));
    }

    /**
     * The records the board shows under the current owner filter.
     */
    protected function boardQuery(): Builder
    {
        $query = $this->boardModel()::query();

        $this->boardScope($query);

        return $this->applyOwnerFilter($query, $this->boardColumn('user_owner_id'));
    }

    protected function perColumn(): int
    {
        return max(1, (int) config('laravel-crm.board.per_column', 20));
    }

    /**
     * Never less than a page, so a short column has room for a card that
     * lands in it.
     */
    protected function stageLimit(int $stageId): int
    {
        return max($this->perColumn(), (int) ($this->stageLimits[$stageId] ?? 0));
    }

    /**
     * The cards on screen, keyed by stage id, in core's display order. The id
     * settles ties on created_at, without which consecutive pages could repeat
     * or skip a card.
     *
     * @return array<int, Collection<int, Model>>
     */
    protected function cardsByStage(): array
    {
        $cards = [];

        foreach ($this->getStages() as $stage) {
            $cards[(int) $stage->getKey()] = $this->boardQuery()
                ->select($this->boardTable() . '.*')
                ->where($this->boardColumn('pipeline_stage_id'), $stage->getKey())
                // Unnumbered cards first on every database, as MySQL and
                // SQLite sort NULL; PostgreSQL would put them last, and
                // placeInStage() renumbers on that assumption.
                ->orderByRaw('CASE WHEN ' . $this->rawBoardColumn('pipeline_stage_order') . ' IS NULL THEN 0 ELSE 1 END')
                ->orderBy($this->boardColumn('pipeline_stage_order'))
                ->orderBy($this->boardColumn('created_at'))
                ->orderBy($this->boardColumn('id'))
                ->limit($this->stageLimit((int) $stage->getKey()))
                ->get();
        }

        return $cards;
    }

    /**
     * Give a card its new stage, first in that column. The caller saves the
     * record, inside a transaction, as this also renumbers the column.
     *
     * The client only sends the column a card was dropped on, so a card that
     * changes column goes to its top, where the boards put it before paging.
     * Left on `pipeline_stage_order`, a card nothing has numbered sorts by
     * created_at and could land past the loaded page and vanish. The rest of
     * the column moves behind it in one statement that keeps their order and
     * their updated_at, as core's `PipelineBoard::reorder()` does. The column
     * then shows one card more, so the drop pushes none of them off screen.
     *
     * A drop back into the same column changes nothing.
     */
    protected function placeInStage(Model $record, PipelineStage $stage): void
    {
        $stageId = (int) $stage->getKey();
        $fromStageId = $record->getAttribute('pipeline_stage_id');

        $record->setAttribute('pipeline_stage_id', $stageId);
        $record->setAttribute('pipeline_id', $stage->getAttribute('pipeline_id'));

        if ($fromStageId !== null && (int) $fromStageId === $stageId) {
            return;
        }

        $order = $this->boardColumn('pipeline_stage_order');

        // Every record in the stage, not only the owner filter's, so a
        // card's place does not depend on who was looking when it moved.
        $peers = fn (): Builder => $this->boardModel()::query()
            ->where($this->boardColumn('pipeline_stage_id'), $stageId)
            ->whereKeyNot($record->getKey());

        $inTheWay = $peers()
            ->where(fn (Builder $query) => $query->whereNull($order)->orWhere($order, '<=', 1))
            ->exists();

        if ($inTheWay) {
            $peers()->toBase()->update([
                'pipeline_stage_order' => DB::raw('COALESCE(' . $this->rawBoardColumn('pipeline_stage_order') . ', 0) + 2'),
            ]);
        }

        $record->setAttribute('pipeline_stage_order', 1);

        $this->stageLimits[$stageId] = $this->stageLimit($stageId) + 1;

        if ($fromStageId !== null) {
            $this->stageLimits[(int) $fromStageId] = max(0, $this->stageLimit((int) $fromStageId) - 1);
        }
    }

    /**
     * Count, per-currency totals (formatted, one per currency) and whether
     * more cards remain, for every stage, from a single grouped query.
     *
     * @return array<int, array{count: int, totals: list<string>, hasMore: bool}>
     */
    public function getStageSummaries(): array
    {
        /** @var array<int, array{count: int, totals: array<string, int>}> $tallies */
        $tallies = [];

        foreach ($this->getStages() as $stage) {
            $tallies[(int) $stage->getKey()] = ['count' => 0, 'totals' => []];
        }

        if ($tallies === []) {
            return [];
        }

        $rows = $this->boardQuery()
            ->toBase()
            ->whereIn($this->boardColumn('pipeline_stage_id'), array_keys($tallies))
            ->selectRaw(sprintf(
                '%1$s as stage_id, %2$s as currency, COUNT(*) as records, SUM(%3$s) as total',
                $this->rawBoardColumn('pipeline_stage_id'),
                $this->rawBoardColumn('currency'),
                $this->rawBoardColumn($this->amountColumn()),
            ))
            ->groupBy($this->boardColumn('pipeline_stage_id'), $this->boardColumn('currency'))
            ->get();

        foreach ($rows as $row) {
            $stageId = (int) $row->stage_id;

            if (! isset($tallies[$stageId])) {
                continue;
            }

            $currency = (string) ($row->currency ?? '');
            $tallies[$stageId]['count'] += (int) $row->records;
            $tallies[$stageId]['totals'][$currency] = ($tallies[$stageId]['totals'][$currency] ?? 0) + (int) $row->total;
        }

        $summaries = [];

        foreach ($tallies as $stageId => $tally) {
            $totals = array_filter($tally['totals']);
            ksort($totals);

            $lines = [];
            foreach ($totals as $currency => $cents) {
                $lines[] = (string) MoneyForm::display($cents, $currency !== '' ? (string) $currency : null);
            }

            $summaries[$stageId] = [
                'count' => $tally['count'],
                'totals' => $lines,
                'hasMore' => $tally['count'] > $this->stageLimit($stageId),
            ];
        }

        return $summaries;
    }

    /**
     * Show the next page of a column.
     */
    public function loadMore(mixed $stageId): void
    {
        abort_unless(static::canAccess(), Response::HTTP_FORBIDDEN);

        $stageId = (int) $this->boardStage($stageId)->getKey();

        $this->stageLimits[$stageId] = $this->stageLimit($stageId) + $this->perColumn();
    }

    /**
     * Livewire's per-trait `updated` hook: a new owner filter sends every
     * column back to its first page, as core does on a filter change.
     */
    public function updatedPagesPipelineBoard(string $property, mixed $value = null): void
    {
        if ($property === 'ownerFilter') {
            $this->stageLimits = [];
        }
    }
}
