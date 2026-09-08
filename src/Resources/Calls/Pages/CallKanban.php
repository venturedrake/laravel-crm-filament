<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Calls\Pages;

use BackedEnum;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use VentureDrake\LaravelCrm\Models\Call;
use VentureDrake\LaravelCrmFilament\Concerns\AuthorizesKanbanBoard;
use VentureDrake\LaravelCrmFilament\Resources\Calls\CallResource;

class CallKanban extends Page
{
    use AuthorizesKanbanBoard;

    protected static string $resource = CallResource::class;

    protected string $view = 'laravel-crm-filament::calls.kanban';

    protected static ?string $title = 'Call kanban';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-view-columns';

    /**
     * Returns calls grouped into the two status columns that mirror core's
     * Call list view. Calls have no dedicated status column, so we treat
     * `finish_at` as the completion proxy: NULL = planned, non-null = done.
     *
     * @return array<string,Collection<int,Call>>
     */
    public function getCallsByStatus(): array
    {
        $calls = Call::query()->orderByDesc('updated_at')->get();

        return [
            'planned' => $calls->filter(fn (Call $c) => $c->finish_at === null)->values(),
            'done' => $calls->filter(fn (Call $c) => $c->finish_at !== null)->values(),
        ];
    }

    /**
     * Drag-to-change-status handler. Destination column `done` stamps
     * `finish_at = now()` (only if not already set); `planned` clears
     * `finish_at` so a previously-done call returning to planned reopens
     * cleanly. Mirrors TaskKanban::moveTask semantics.
     */
    public function moveCall(string $externalId, string $status): void
    {
        $call = Call::query()->where('external_id', $externalId)->first();
        if (! $call) {
            return;
        }

        $this->authorizeKanbanEdit($call);

        if ($status === 'done') {
            if (! $call->finish_at) {
                $call->forceFill(['finish_at' => now()])->save();
            }

            return;
        }

        if ($call->finish_at) {
            $call->forceFill(['finish_at' => null])->save();
        }
    }
}
