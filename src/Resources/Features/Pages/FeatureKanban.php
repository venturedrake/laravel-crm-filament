<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Features\Pages;

use BackedEnum;
use Filament\Actions;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;
use VentureDrake\LaravelCrm\Models\Feature;
use VentureDrake\LaravelCrm\Models\FeatureStatus;
use VentureDrake\LaravelCrmFilament\Concerns\AuthorizesKanbanBoard;
use VentureDrake\LaravelCrmFilament\Concerns\FiltersBoardByOwner;
use VentureDrake\LaravelCrmFilament\Resources\Features\FeatureResource;

class FeatureKanban extends Page
{
    use AuthorizesKanbanBoard;
    use FiltersBoardByOwner;

    protected static string $resource = FeatureResource::class;

    protected string $view = 'laravel-crm-filament::features.kanban';

    protected static ?string $title = 'Feature roadmap';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-view-columns';

    protected function getHeaderActions(): array
    {
        return [
            ...FeatureResource::listKanbanToggleActions('kanban'),
            Actions\CreateAction::make()
                ->url(FeatureResource::getUrl('create')),
        ];
    }

    /**
     * @return Collection<int, FeatureStatus>
     */
    public function getStatuses(): Collection
    {
        return FeatureStatus::query()
            ->orderBy('order')
            ->get();
    }

    /**
     * @return array<int, \Illuminate\Database\Eloquent\Collection<int, Feature>>
     */
    public function getFeaturesByStatus(): array
    {
        $features = Feature::query()
            ->when($this->ownerFilter, fn ($q) => $q->where('user_owner_id', $this->ownerFilter))
            ->whereNotNull('feature_status_id')
            ->orderByDesc('updated_at')
            ->get();

        return $features->groupBy('feature_status_id')->all();
    }

    public function moveFeature(string $externalId, int $statusId): void
    {
        $feature = Feature::query()->where('external_id', $externalId)->first();
        if (! $feature) {
            return;
        }

        $this->authorizeKanbanEdit($feature);

        $feature->feature_status_id = $statusId;
        $feature->save();
    }
}
