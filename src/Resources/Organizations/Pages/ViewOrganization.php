<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Organizations\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Livewire;
use Illuminate\Contracts\Support\Htmlable;
use VentureDrake\LaravelCrmFilament\Concerns\HasCrmSideBySideRelationManagers;
use VentureDrake\LaravelCrmFilament\RelationManagers\RelatedOrganizationsRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\RelatedPeopleRelationManager;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\OrganizationResource;

class ViewOrganization extends ViewRecord
{
    use HasCrmSideBySideRelationManagers;

    protected static string $resource = OrganizationResource::class;

    public function getTitle(): string | Htmlable
    {
        return $this->getRecordTitle();
    }

    protected function getHeaderActions(): array
    {
        return [
            OrganizationResource::backToIndexAction(),
            Actions\EditAction::make()
                ->icon('heroicon-m-pencil-square')
                ->hiddenLabel()
                ->tooltip('Edit'),
            OrganizationResource::warnOfLinkedRecords(Actions\DeleteAction::make())
                ->icon('heroicon-m-trash')
                ->hiddenLabel()
                ->tooltip('Delete'),
        ];
    }

    protected function getLeftColumnComponents(): array
    {
        $ownerData = [
            'ownerRecord' => $this->getRecord(),
            'pageClass' => static::class,
        ];

        return [
            $this->getInfolistContentComponent(),
            Livewire::make(RelatedPeopleRelationManager::class, $ownerData)
                ->key('related-people-' . $this->getRecord()->getKey()),
            Livewire::make(RelatedOrganizationsRelationManager::class, $ownerData)
                ->key('related-organizations-' . $this->getRecord()->getKey()),
        ];
    }
}
