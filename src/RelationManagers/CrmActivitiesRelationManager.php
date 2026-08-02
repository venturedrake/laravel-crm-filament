<?php

namespace VentureDrake\LaravelCrmFilament\RelationManagers;

use VentureDrake\LaravelCrmFilament\Concerns\RollsUpRelatedActivity;

class CrmActivitiesRelationManager extends ActivitiesRelationManager
{
    use RollsUpRelatedActivity;

    protected string $view = 'laravel-crm-filament::crm-activity';
}
