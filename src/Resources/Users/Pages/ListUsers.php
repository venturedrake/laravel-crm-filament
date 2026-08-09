<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Users\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use VentureDrake\LaravelCrmFilament\Concerns\Imports\UserImporter;
use VentureDrake\LaravelCrmFilament\Concerns\ImportsCsv;
use VentureDrake\LaravelCrmFilament\Resources\UserInvitations\UserInvitationResource;
use VentureDrake\LaravelCrmFilament\Resources\Users\Pages\Concerns\HasUserInviteAction;
use VentureDrake\LaravelCrmFilament\Resources\Users\UserResource;
use VentureDrake\LaravelCrmFilament\Support\TenancyGuard;

class ListUsers extends ListRecords
{
    use HasUserInviteAction;

    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->userInviteAction(),
            UserInvitationResource::backToIndexAction(),
            // Hidden entirely under multi-tenancy: a CSV import would produce
            // untenanted users. Operators are pointed at core's tenant-aware
            // /crm/users instead. See TenancyGuard.
            ImportsCsv::action(UserImporter::class, visible: fn (): bool => ! TenancyGuard::isEnabled()),
            Actions\CreateAction::make(),
        ];
    }
}
