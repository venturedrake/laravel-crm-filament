<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Users\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use VentureDrake\LaravelCrmFilament\Concerns\Imports\UserImporter;
use VentureDrake\LaravelCrmFilament\Concerns\ImportsCsv;
use VentureDrake\LaravelCrmFilament\Resources\Users\Pages\Concerns\HasUserInviteAction;
use VentureDrake\LaravelCrmFilament\Resources\Users\UserResource;

class ListUsers extends ListRecords
{
    use HasUserInviteAction;

    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->userInviteAction(),
            ImportsCsv::action(UserImporter::class),
            Actions\CreateAction::make(),
        ];
    }
}
