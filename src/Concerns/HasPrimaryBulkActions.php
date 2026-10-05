<?php

namespace VentureDrake\LaravelCrmFilament\Concerns;

use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use VentureDrake\LaravelCrm\Models\Label;
use VentureDrake\LaravelCrm\Models\PipelineStage;

/**
 * Shared bulk-action factories used on the Lead, Deal, Quote, Order, and Invoice
 * list tables. Resources opt in by adding `static::primaryBulkActionGroup()`
 * to their `toolbarActions([...])` array.
 *
 * Each action authorises per row via the resource's policy (Filament's
 * BulkAction respects `update` / `delete` gates by default), so a user with
 * partial permissions only ever mutates the records they are allowed to touch.
 */
trait HasPrimaryBulkActions
{
    /**
     * Returns the BulkActionGroup for this resource. Pass `withPipelineStage:
     * true` for Lead/Deal where a stage select makes sense; leave it false on
     * Quote/Order/Invoice where the AC says the action is not exposed.
     */
    public static function primaryBulkActionGroup(bool $withPipelineStage = false): BulkActionGroup
    {
        $actions = [
            static::assignOwnerBulkAction(),
        ];

        if ($withPipelineStage) {
            $actions[] = static::changePipelineStageBulkAction();
        }

        $actions[] = static::applyLabelsBulkAction();
        $actions[] = static::archiveBulkAction();

        return BulkActionGroup::make($actions);
    }

    public static function assignOwnerBulkAction(): BulkAction
    {
        return BulkAction::make('assignOwner')
            ->label(__('laravel-crm-filament::labels.actions.assign_owner'))
            ->icon('heroicon-o-user')
            ->schema([
                Select::make('user_owner_id')
                    ->label(__('laravel-crm-filament::labels.fields.owner'))
                    ->options(fn () => static::primaryBulkActionOwnerOptions())
                    ->searchable()
                    ->getSearchResultsUsing(fn (?string $search): array => static::primaryBulkActionOwnerOptions($search))
                    ->getOptionLabelUsing(fn ($value): ?string => static::primaryBulkActionOwnerLabel($value))
                    ->required(),
            ])
            ->action(function (Collection $records, array $data): void {
                $ownerId = (int) $data['user_owner_id'];
                $touched = 0;

                foreach ($records as $record) {
                    if (! static::can('update', $record)) {
                        continue;
                    }
                    $record->user_owner_id = $ownerId;
                    $record->save();
                    $touched++;
                }

                Notification::make()
                    ->title(__('laravel-crm-filament::labels.actions.assign_owner'))
                    ->body($touched . ' record(s) updated')
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    public static function changePipelineStageBulkAction(): BulkAction
    {
        return BulkAction::make('changePipelineStage')
            ->label(__('laravel-crm-filament::labels.actions.change_pipeline_stage'))
            ->icon('heroicon-o-bars-3-bottom-left')
            ->schema([
                Select::make('pipeline_stage_id')
                    ->label(__('laravel-crm-filament::labels.sales.pipeline_stage'))
                    ->options(fn () => PipelineStage::query()->orderBy('order')->pluck('name', 'id'))
                    ->searchable()
                    ->preload()
                    ->required(),
            ])
            ->action(function (Collection $records, array $data): void {
                $stageId = (int) $data['pipeline_stage_id'];
                $stage = PipelineStage::find($stageId);
                $touched = 0;

                foreach ($records as $record) {
                    if (! static::can('update', $record)) {
                        continue;
                    }
                    $record->pipeline_stage_id = $stageId;
                    if ($stage) {
                        $record->pipeline_id = $stage->pipeline_id;
                    }
                    $record->save();
                    $touched++;
                }

                Notification::make()
                    ->title(__('laravel-crm-filament::labels.actions.change_pipeline_stage'))
                    ->body($touched . ' record(s) moved')
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    public static function applyLabelsBulkAction(): BulkAction
    {
        return BulkAction::make('applyLabels')
            ->label(__('laravel-crm-filament::labels.actions.apply_labels'))
            ->icon('heroicon-o-tag')
            ->schema([
                Select::make('label_ids')
                    ->label(__('laravel-crm-filament::labels.fields.labels'))
                    ->options(fn () => Label::query()->orderBy('name')->pluck('name', 'id'))
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->required(),
            ])
            ->action(function (Collection $records, array $data): void {
                $labelIds = array_map('intval', (array) ($data['label_ids'] ?? []));
                $touched = 0;

                foreach ($records as $record) {
                    if (! static::can('update', $record)) {
                        continue;
                    }
                    $record->labels()->syncWithoutDetaching($labelIds);
                    $touched++;
                }

                Notification::make()
                    ->title(__('laravel-crm-filament::labels.actions.apply_labels'))
                    ->body($touched . ' record(s) tagged')
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    public static function archiveBulkAction(): BulkAction
    {
        return BulkAction::make('archive')
            ->label(__('laravel-crm-filament::labels.actions.archive'))
            ->icon('heroicon-o-archive-box')
            ->color('danger')
            ->requiresConfirmation()
            ->action(function (Collection $records): void {
                $archived = 0;

                foreach ($records as $record) {
                    if (! static::can('delete', $record)) {
                        continue;
                    }
                    // Soft-delete on every primary model (Lead/Deal/Quote/Order/Invoice).
                    $record->delete();
                    $archived++;
                }

                Notification::make()
                    ->title(__('laravel-crm-filament::labels.actions.archive'))
                    ->body($archived . ' record(s) archived')
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Owner options sourced from the configured auth user model, searched on
     * the server and capped, like the board owner filter (FiltersBoardByOwner).
     * Static ->options() over every user meant Filament searched them in the
     * browser after shipping the lot.
     *
     * @return array<int|string, string>
     */
    protected static function primaryBulkActionOwnerOptions(?string $search = null): array
    {
        $userClass = config('auth.providers.users.model');

        if (! $userClass || ! class_exists($userClass)) {
            return [];
        }

        $search = trim((string) $search);

        return $userClass::query()
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%' . $search . '%'))
            ->orderBy('name')
            ->limit(50)
            ->pluck('name', 'id')
            ->all();
    }

    protected static function primaryBulkActionOwnerLabel(mixed $value): ?string
    {
        $userClass = config('auth.providers.users.model');

        if (blank($value) || ! $userClass || ! class_exists($userClass)) {
            return null;
        }

        return $userClass::query()->whereKey($value)->value('name');
    }
}
