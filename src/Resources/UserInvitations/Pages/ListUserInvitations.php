<?php

namespace VentureDrake\LaravelCrmFilament\Resources\UserInvitations\Pages;

use Filament\Resources\Pages\ListRecords;
use VentureDrake\LaravelCrmFilament\Resources\UserInvitations\UserInvitationResource;
use VentureDrake\LaravelCrmFilament\Resources\Users\UserResource;

class ListUserInvitations extends ListRecords
{
    protected static string $resource = UserInvitationResource::class;

    public function getTitle(): string
    {
        return __('laravel-crm-filament::labels.invitations.pending');
    }

    protected function getHeaderActions(): array
    {
        return [
            UserResource::backToIndexAction(),
        ];
    }
}
