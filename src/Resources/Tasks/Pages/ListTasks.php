<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Tasks\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use VentureDrake\LaravelCrm\Models\Task;
use VentureDrake\LaravelCrmFilament\Resources\Tasks\TaskResource;

class ListTasks extends ListRecords
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'open' => Tab::make('Open')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('completed_at')),
            'today' => Tab::make('Today')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('completed_at')->whereDate('due_at', today()))
                ->badge(fn () => Task::query()->whereNull('completed_at')->whereDate('due_at', today())->count() ?: null)
                ->badgeColor('warning'),
            'overdue' => Tab::make('Overdue')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('completed_at')->whereDate('due_at', '<', today()))
                ->badge(fn () => Task::query()->whereNull('completed_at')->whereDate('due_at', '<', today())->count() ?: null)
                ->badgeColor('danger'),
            'completed' => Tab::make('Completed')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('completed_at')),
        ];
    }
}
