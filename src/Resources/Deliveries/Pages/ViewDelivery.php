<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Deliveries\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use VentureDrake\LaravelCrm\Models\Delivery;
use VentureDrake\LaravelCrmFilament\Concerns\DownloadsPdf;
use VentureDrake\LaravelCrmFilament\Concerns\HasCrmSideBySideRelationManagers;
use VentureDrake\LaravelCrmFilament\Resources\Deliveries\DeliveryResource;

class ViewDelivery extends ViewRecord
{
    use Concerns\HasDeliveryPortalAction;
    use DownloadsPdf;
    use HasCrmSideBySideRelationManagers;

    protected static string $resource = DeliveryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeliveryResource::backToIndexAction(),
            $this->downloadPdfAction(fn (Delivery $record) => $this->streamPdfDownload($record, 'delivery'))
                ->button()
                ->hiddenLabel()
                ->icon('heroicon-m-arrow-down-tray'),
            $this->deliveryPortalAction()
                ->button()
                ->hiddenLabel()
                ->icon('heroicon-m-arrow-top-right-on-square')
                ->color('gray'),
            Actions\EditAction::make()
                ->button()
                ->hiddenLabel()
                ->icon('heroicon-m-pencil-square'),
            Actions\DeleteAction::make()
                ->button()
                ->hiddenLabel()
                ->icon('heroicon-m-trash'),
        ];
    }

    public function getHeading(): string | Htmlable
    {
        $title = $this->record?->title;

        return $title !== null && $title !== '' ? $title : parent::getHeading();
    }
}
