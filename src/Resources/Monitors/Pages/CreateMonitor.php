<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Monitors\Pages;

use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrm\Services\MonitorService;
use VentureDrake\LaravelCrmFilament\Resources\Monitors\MonitorResource;

class CreateMonitor extends CreateRecord
{
    protected static string $resource = MonitorResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(MonitorService::class)->create(MonitorResource::withSchemeUrl($data));
    }

    public function getMaxContentWidth(): Width | string | null
    {
        return Width::Full;
    }
}
