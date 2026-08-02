<?php

namespace VentureDrake\LaravelCrmFilament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use VentureDrake\LaravelCrm\Models\Activity;
use VentureDrake\LaravelCrm\Models\Call;
use VentureDrake\LaravelCrm\Models\File;
use VentureDrake\LaravelCrm\Models\Lunch;
use VentureDrake\LaravelCrm\Models\Meeting;
use VentureDrake\LaravelCrm\Models\Note;
use VentureDrake\LaravelCrm\Models\Task;
use VentureDrake\LaravelCrmFilament\Concerns\AuthorizesCrmSettingsPage;
use VentureDrake\LaravelCrmFilament\LaravelCrmPlugin;

/**
 * Aggregated activity feed replacing the six per-type sidebar entries
 * (Activities/Notes/Calls/Meetings/Lunches/Files) with a single tabbed
 * page. The scope toggle switches between the current user's activity
 * and everyone's; the tab picker narrows the feed to a single type.
 *
 * Skeleton only for US-002 — data loading, filters, and the Blade view
 * land in later stories in the series.
 */
class ActivityFeed extends Page
{
    use AuthorizesCrmSettingsPage;

    protected static string $crmPermission = 'view crm activities';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-bolt';

    protected static ?string $title = 'Activity';

    protected static ?string $slug = 'activities';

    protected static ?int $navigationSort = 40;

    protected string $view = 'laravel-crm-filament::activity-feed';

    #[Url]
    public string $scope = 'mine';

    #[Url]
    public string $tab = 'all';

    public static function getNavigationGroup(): ?string
    {
        return LaravelCrmPlugin::get()->getNavigationGroup() ?? 'Activity';
    }

    public function setScope(string $scope): void
    {
        $this->scope = $scope;
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
    }

    public function getActivities(): LengthAwarePaginator
    {
        $query = Activity::query()->latest();

        if ($this->scope === 'mine') {
            $query->where('causeable_id', auth()->id())
                ->where('causeable_type', auth()->user()->getMorphClass());
        }

        $map = $this->recordableTypeMap();

        if ($this->tab !== 'all' && isset($map[$this->tab])) {
            $query->where('recordable_type', $map[$this->tab]);
        }

        return $query->paginate(25);
    }

    private function recordableTypeMap(): array
    {
        return [
            'notes' => Note::class,
            'tasks' => Task::class,
            'calls' => Call::class,
            'meetings' => Meeting::class,
            'lunches' => Lunch::class,
            'files' => File::class,
        ];
    }
}
