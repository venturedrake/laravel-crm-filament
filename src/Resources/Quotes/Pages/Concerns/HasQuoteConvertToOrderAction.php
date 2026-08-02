<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\Concerns;

use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use VentureDrake\LaravelCrm\Models\Pipeline;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrm\Services\OrderService;
use VentureDrake\LaravelCrmFilament\Resources\Orders\OrderResource;
use VentureDrake\LaravelCrmFilament\Support\FormPayload;

/**
 * Convert-to-Order action shown on the Quote view page. Copies all line items,
 * stamps quote.accepted_at, sets the matching pipeline stage if available, and
 * flashes a notification with a link to the new Order.
 */
trait HasQuoteConvertToOrderAction
{
    protected function quoteConvertToOrderAction(): Action
    {
        return Action::make('convertToOrder')
            ->label(__('laravel-crm-filament::labels.actions.convert_to_order'))
            ->icon('heroicon-o-arrow-right-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Convert quote to order')
            ->modalDescription('Creates a new order with the same line items and marks this quote as accepted.')
            ->visible(fn (Quote $record): bool => $record->accepted_at !== null && $record->orders()->count() === 0)
            ->action(function (Quote $record, OrderService $orderService): void {
                $payload = $this->buildOrderPayloadFromQuote($record);

                $order = $orderService->create(
                    FormPayload::wrap($payload),
                    $record->person,
                    $record->organization,
                );

                $record->update([
                    'accepted_at' => Carbon::now(),
                    'pipeline_stage_id' => $this->acceptedQuotePipelineStageId() ?? $record->pipeline_stage_id,
                ]);

                $url = OrderResource::getUrl('view', ['record' => $order]);

                Notification::make()
                    ->title('Order ' . $order->order_id . ' created')
                    ->body('Quote converted successfully.')
                    ->success()
                    ->actions([
                        Action::make('open')
                            ->label(__('laravel-crm-filament::labels.actions.open_order'))
                            ->url($url),
                    ])
                    ->send();
            });
    }

    protected function buildOrderPayloadFromQuote(Quote $record): array
    {
        $products = [];

        foreach ($record->quoteProducts as $quoteProduct) {
            $products[] = [
                'id' => $quoteProduct->product_id,
                'quote_product_id' => $quoteProduct->id,
                'quantity' => $quoteProduct->quantity,
                'unit_price' => $quoteProduct->price !== null ? $quoteProduct->price / 100 : 0,
                'amount' => $quoteProduct->amount !== null ? $quoteProduct->amount / 100 : 0,
                'comments' => $quoteProduct->comments,
            ];
        }

        return [
            'lead_id' => $record->lead_id,
            'deal_id' => $record->deal_id,
            'quote_id' => $record->id,
            'description' => $record->description,
            'reference' => $record->reference,
            'currency' => $record->currency,
            'sub_total' => $record->subtotal !== null ? $record->subtotal / 100 : null,
            'discount' => $record->discount !== null ? $record->discount / 100 : null,
            'tax' => $record->tax !== null ? $record->tax / 100 : null,
            'adjustment' => $record->adjustments !== null ? $record->adjustments / 100 : null,
            'total' => $record->total !== null ? $record->total / 100 : null,
            'user_owner_id' => $record->user_owner_id,
            'products' => $products,
            'addresses' => [],
            'labels' => $record->labels()->pluck($record->labels()->getTable() . '.id')->all(),
        ];
    }

    private function acceptedQuotePipelineStageId(): ?int
    {
        $pipeline = Pipeline::where('model', Quote::class)->first();

        if (! $pipeline) {
            return null;
        }

        return optional($pipeline->pipelineStages()->where('name', 'Accepted')->first())->id;
    }
}
