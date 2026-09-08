<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Tasks\Pages;

use BackedEnum;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use VentureDrake\LaravelCrm\Models\Task;
use VentureDrake\LaravelCrmFilament\Concerns\AuthorizesKanbanBoard;
use VentureDrake\LaravelCrmFilament\Resources\Tasks\TaskResource;

class TaskKanban extends Page
{
    use AuthorizesKanbanBoard;

    protected static string $resource = TaskResource::class;

    protected string $view = 'laravel-crm-filament::tasks.kanban';

    protected static ?string $title = 'Task kanban';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-view-columns';

    /**
     * Returns tasks grouped into the four status columns the AC enumerates.
     *
     * @return array<string,Collection<int,Task>>
     */
    public function getTasksByStatus(): array
    {
        $today = today();
        $tasks = Task::query()->orderByDesc('updated_at')->get();

        return [
            'open' => $tasks->filter(fn (Task $t) => $t->completed_at === null
                && ($t->due_at === null || $t->due_at->isAfter($today->copy()->endOfDay())))->values(),
            'today' => $tasks->filter(fn (Task $t) => $t->completed_at === null
                && $t->due_at !== null
                && $t->due_at->isSameDay($today))->values(),
            'overdue' => $tasks->filter(fn (Task $t) => $t->completed_at === null
                && $t->due_at !== null
                && $t->due_at->isBefore($today))->values(),
            'completed' => $tasks->filter(fn (Task $t) => $t->completed_at !== null)->values(),
        ];
    }

    /**
     * Drag-to-complete handler. When the destination column is `completed`,
     * stamp `completed_at = now()`; for the other columns, clear `completed_at`
     * so a previously completed task returning to an open column reopens cleanly.
     */
    public function moveTask(string $externalId, string $status): void
    {
        $task = Task::query()->where('external_id', $externalId)->first();
        if (! $task) {
            return;
        }

        $this->authorizeKanbanEdit($task);

        if ($status === 'completed') {
            if (! $task->completed_at) {
                $task->forceFill(['completed_at' => now()])->save();
            }

            return;
        }

        if ($task->completed_at) {
            $task->forceFill(['completed_at' => null])->save();
        }
    }
}
