<?php

namespace VentureDrake\LaravelCrmFilament\Widgets;

use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use VentureDrake\LaravelCrm\Models\Activity;

class RecentActivityList extends TableWidget
{
    protected static ?int $sort = 10;

    protected static ?string $heading = 'Recent activity';

    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Activity::query()
                ->with(['causeable', 'timelineable', 'recordable'])
                ->orderByDesc('created_at'))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('laravel-crm-filament::labels.fields.when'))
                    ->since(),
                Tables\Columns\TextColumn::make('causeable.name')
                    ->label(__('laravel-crm-filament::labels.fields.user'))
                    ->placeholder('System'),
                Tables\Columns\TextColumn::make('recordable_type')
                    ->label(__('laravel-crm-filament::labels.fields.action'))
                    ->formatStateUsing(fn (?string $state, $record) => static::describeAction($state, $record)),
                Tables\Columns\TextColumn::make('timelineable_type')
                    ->label(__('laravel-crm-filament::labels.fields.on'))
                    ->formatStateUsing(fn (?string $state, $record) => static::describeTimelineable($state, $record)),
            ])
            ->paginated([10, 25])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('No activity yet');
    }

    protected static function describeAction(?string $recordableType, $row): string
    {
        $verb = match (class_basename($recordableType ?? '')) {
            'Note' => 'logged a note',
            'Task' => 'created a task',
            'Call' => 'logged a call',
            'Meeting' => 'scheduled a meeting',
            'Lunch' => 'scheduled lunch',
            default => 'logged activity',
        };

        return $verb;
    }

    protected static function describeTimelineable(?string $type, $row): string
    {
        if (! $type || ! $row->timelineable) {
            return '—';
        }
        $parent = $row->timelineable;
        $label = class_basename($type);
        $title = $parent->title
            ?? $parent->name
            ?? trim(($parent->first_name ?? '') . ' ' . ($parent->last_name ?? ''))
            ?? '#' . $parent->getKey();

        return $label . ' · ' . trim((string) $title);
    }
}
