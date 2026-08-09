<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\Concerns;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use VentureDrake\LaravelCrm\Models\DeliveryProduct;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Services\DeliveryService;
use VentureDrake\LaravelCrmFilament\Resources\Deliveries\DeliveryResource;
use VentureDrake\LaravelCrmFilament\Support\FormPayload;
use VentureDrake\LaravelCrmFilament\Support\OrderDrawdownPrefill;

/**
 * Convert-to-Delivery action on the Order view page.
 *
 * Pre-fills the quantity still *outstanding* on each line, not the full
 * ordered quantity — see OrderDrawdownPrefill. Lines with nothing left to
 * deliver are dropped.
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
            ->modalDescription('Pre-fills the quantity still outstanding on each line item.')
            ->action(function (Order $record, DeliveryService $deliveryService): void {
                // Every line is already fully delivered, so the prefill is
                // empty. Without this the service still writes a Delivery — a
                // document with no lines, announced with a success toast.
                if (! OrderDrawdownPrefill::hasRemaining($record, DeliveryProduct::class, 'delivery')) {
                    Notification::make()
                        ->title(__('laravel-crm-filament::labels.notifications.nothing_left_to_deliver'))
                        ->body(__('laravel-crm-filament::labels.notifications.nothing_left_to_deliver_body'))
                        ->warning()
                        ->send();

                    return;
                }

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
        return [
            'order_id' => $record->id,
            'delivery_expected' => now(),
            'delivered_on' => null,
            'user_owner_id' => $record->user_owner_id,
            'products' => OrderDrawdownPrefill::deliveryProducts($record),
            'addresses' => [],
        ];
    }
}
