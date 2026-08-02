<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Users\Pages;

use Filament\Actions;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Throwable;
use VentureDrake\LaravelCrmFilament\LaravelCrmPlugin;
use VentureDrake\LaravelCrmFilament\Resources\Teams\CrmTeamResource;
use VentureDrake\LaravelCrmFilament\Resources\Users\UserResource;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    public function getTitle(): string | Htmlable
    {
        return $this->getRecordTitle();
    }

    protected function getHeaderActions(): array
    {
        return [
            UserResource::backToIndexAction(),
            Actions\EditAction::make()
                ->icon('heroicon-m-pencil-square')
                ->hiddenLabel()
                ->tooltip('Edit'),
            Actions\DeleteAction::make()
                ->icon('heroicon-m-trash')
                ->hiddenLabel()
                ->tooltip('Delete'),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 2])->schema([
                Group::make([
                    $this->getInfolistContentComponent(),
                ])->columnSpan(['lg' => 1]),
                Group::make([
                    $this->buildTeamsSection(),
                ])->columnSpan(['lg' => 1]),
            ]),
        ]);
    }

    protected function buildTeamsSection(): Section
    {
        $record = $this->getRecord();
        $teams = collect();

        if ($record && method_exists($record, 'crmTeams')) {
            $teams = $record->crmTeams()->orderBy('name')->get();
        }

        $heading = __('laravel-crm-filament::labels.sections.teams') . ' (' . $teams->count() . ')';

        return Section::make($heading)
            ->schema(
                $teams->isEmpty()
                    ? [
                        TextEntry::make('no_teams')
                            ->hiddenLabel()
                            ->state(__('laravel-crm-filament::labels.misc.no_teams')),
                    ]
                    : $teams->map(fn ($team) => TextEntry::make('team_' . $team->id)
                        ->hiddenLabel()
                        ->icon('heroicon-m-user-group')
                        ->state($team->name)
                        ->color('primary')
                        ->url(static::crmTeamUrl($team)))
                        ->all()
            );
    }

    /**
     * Link a team name to CrmTeamResource, but only while the `teams` module
     * is on — the resource isn't registered on the panel otherwise, and
     * getUrl() on an unregistered resource throws.
     */
    protected static function crmTeamUrl(object $team): ?string
    {
        try {
            if (! LaravelCrmPlugin::get()->isModuleEnabled('teams')) {
                return null;
            }

            return CrmTeamResource::getUrl('view', ['record' => $team]);
        } catch (Throwable) {
            return null;
        }
    }
}
