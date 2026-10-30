<?php

namespace VentureDrake\LaravelCrmFilament\Widgets;

use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use VentureDrake\LaravelCrm\Models\Task;

class TasksDueTodayList extends TableWidget
{
    protected static ?int $sort = 9;

    protected static ?string $heading = null;

    protected int | string | array $columnSpan = 'full';

    public function getHeading(): ?string
    {
        return __('laravel-crm-filament::labels.dashboard.upcoming_tasks');
    }

    /**
     * TableWidget renders getTableHeading(), not getHeading(), and falls back
     * to the humanised class name ("Tasks Due Today List") when it is null.
     */
    protected function getTableHeading(): string
    {
        return $this->getHeading();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Task::query()
                ->whereNull('completed_at')
                ->orderBy('due_at')
                ->limit(5))
            ->description(__('laravel-crm-filament::labels.dashboard.overdue_n', [
                'count' => static::overdueCount(),
            ]))
            ->columns([
                Tables\Columns\TextColumn::make('name')->limit(60)->wrap(),
                Tables\Columns\TextColumn::make('due_at')->dateTime()->label(__('laravel-crm-filament::labels.money.due')),
                Tables\Columns\TextColumn::make('assignedToUser.name')->label(__('laravel-crm-filament::labels.fields.assignee'))->placeholder('Unassigned'),
                Tables\Columns\TextColumn::make('taskable_type')
                    ->label(__('laravel-crm-filament::labels.fields.linked_to'))
                    ->formatStateUsing(fn ($state) => class_basename($state ?? '')),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('laravel-crm-filament::labels.dashboard.no_upcoming_tasks'));
    }

    protected static function overdueCount(): int
    {
        return Task::query()
            ->whereNull('completed_at')
            ->where('due_at', '<', now())
            ->count();
    }
}
