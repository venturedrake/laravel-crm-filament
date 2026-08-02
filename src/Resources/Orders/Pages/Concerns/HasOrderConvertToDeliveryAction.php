<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\Concerns;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Services\DeliveryService;
use VentureDrake\LaravelCrmFilament\Resources\Deliveries\DeliveryResource;
use VentureDrake\LaravelCrmFilament\Support\FormPayload;

/**
 * Convert-to-Delivery action on Order view page. Pre-fills the full ordered
 * quantity for every line item (no per-line outstanding-quantity math here:
 * Delivery::deliveryProducts is built straight from order line quantities).
 */
trait HasOrderConvertToDeliveryAction
{
    protected function orderConvertToDeliveryAction(): Action
    {
        return Action::make('convertToDelivery')
            ->label(__('laravel-crm-filament::labels.actions.convert_to_delivery'))
            ->icon('heroicon-o-truck')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Create delivery from order')
            ->modalDescription('Pre-fills the full ordered quantity for every line item.')
            ->action(function (Order $record, DeliveryService $deliveryService): void {
                $payload = $this->buildDeliveryPayloadFromOrder($record);

                $delivery = $deliveryService->create(
                    FormPayload::wrap($payload),
                    $record->person,
                    $record->organization,
                );

                $url = DeliveryResource::getUrl('view', ['record' => $delivery]);

                Notification::make()
                    ->title('Delivery ' . $delivery->delivery_id . ' created')
                    ->body('Order converted to delivery.')
                    ->success()
                    ->actions([
                        Action::make('open')
                            ->label(__('laravel-crm-filament::labels.actions.open_delivery'))
                            ->url($url),
                    ])
                    ->send();
            });
    }

    protected function buildDeliveryPayloadFromOrder(Order $record): array
    {
        $products = [];

        foreach ($record->orderProducts as $orderProduct) {
            $products[] = [
                'order_product_id' => $orderProduct->id,
                'quantity' => $orderProduct->quantity,
            ];
        }

        return [
            'order_id' => $record->id,
            'delivery_expected' => now(),
            'delivered_on' => null,
            'user_owner_id' => $record->user_owner_id,
            'products' => $products,
            'addresses' => [],
        ];
    }
}
