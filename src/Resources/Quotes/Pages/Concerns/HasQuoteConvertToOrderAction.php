<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\Concerns;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrm\Models\QuoteProduct;
use VentureDrake\LaravelCrm\Services\OrderService;
use VentureDrake\LaravelCrm\Services\QuoteStateService;
use VentureDrake\LaravelCrm\Support\LineAmount;
use VentureDrake\LaravelCrm\Support\Quantity;
use VentureDrake\LaravelCrmFilament\Resources\Orders\OrderResource;
use VentureDrake\LaravelCrmFilament\Support\FormPayload;

/**
 * Convert-to-Order action shown on the Quote view page. Copies the quote's
 * line items that are still to be ordered, accepts the quote through core's
 * QuoteStateService if it is not accepted yet, and flashes a notification
 * with a link to the new Order.
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
            // As core 2.7.0's QuoteShow: offered on an accepted quote until
            // its orders cover every line (a follow-up order takes the rest).
            ->visible(fn (Quote $record): bool => $record->accepted_at !== null
                && (! $record->hasOrders() || ! $record->orderComplete()))
            ->action(function (Quote $record, OrderService $orderService, Action $action): void {
                $record->unsetRelation('orders');
                $record->unsetRelation('quoteProducts');

                // Ordered in full since the page was drawn (another tab, a
                // double click): a second order would duplicate the lines.
                if ($record->hasOrders() && $record->orderComplete()) {
                    Notification::make()
                        ->title(ucfirst(__('laravel-crm::lang.quote_already_ordered')))
                        ->danger()
                        ->send();

                    $action->halt();

                    return;
                }

                $payload = $this->buildOrderPayloadFromQuote($record);

                $order = DB::transaction(function () use ($record, $orderService, $payload) {
                    // Through the state service, so the stage moves with the
                    // timestamp; an accepted quote keeps its accepted_at.
                    if ($record->accepted_at === null) {
                        app(QuoteStateService::class)->accept($record);
                    }

                    return $orderService->create(
                        FormPayload::wrap($payload),
                        $record->person,
                        $record->organization,
                    );
                });

                $record->unsetRelation('orders');

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
        // Fresh, so a follow-up conversion sees the order made a moment ago.
        $record->unsetRelation('orders');
        $isFollowUp = $record->hasOrders();

        $products = [];

        foreach ($record->quoteProducts()->orderBy('order')->orderBy('id')->get() as $quoteProduct) {
            $remaining = $isFollowUp ? $this->quoteProductRemaining($record, $quoteProduct) : null;

            if ($remaining !== null && ! Quantity::isPositive($remaining)) {
                continue;
            }

            $amount = $remaining !== null
                ? LineAmount::amountCents($quoteProduct->price, $remaining)
                : $quoteProduct->amount;

            $products[] = [
                'id' => $quoteProduct->product_id,
                'product_variation_id' => $quoteProduct->product_variation_id,
                'quote_product_id' => $quoteProduct->id,
                'quantity' => $remaining ?? $quoteProduct->quantity,
                'unit_price' => $quoteProduct->price !== null ? $quoteProduct->price / 100 : 0,
                'amount' => $amount !== null ? $amount / 100 : 0,
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
            // Core's OrderService re-sums subtotal, tax and total from the
            // lines; the quote's discount and adjustment belong to the first
            // order only, so a follow-up does not take them again.
            'sub_total' => $record->subtotal !== null ? $record->subtotal / 100 : null,
            'discount' => ! $isFollowUp && $record->discount !== null ? $record->discount / 100 : null,
            'tax' => $record->tax !== null ? $record->tax / 100 : null,
            'adjustment' => ! $isFollowUp && $record->adjustments !== null ? $record->adjustments / 100 : null,
            'total' => $record->total !== null ? $record->total / 100 : null,
            'user_owner_id' => $record->user_owner_id,
            'products' => $products,
            'addresses' => [],
            'labels' => $record->labels()->pluck($record->labels()->getRelated()->qualifyColumn('id'))->all(),
        ];
    }

    /**
     * What is left of a quote line after the quote's orders, the way core's
     * Quote::orderComplete() counts it (soft-deleted orders don't count).
     */
    private function quoteProductRemaining(Quote $record, QuoteProduct $quoteProduct): float
    {
        $quantity = Quantity::toFloat($quoteProduct->quantity);

        foreach ($record->orders as $order) {
            if ($orderProduct = $order->orderProducts->firstWhere('quote_product_id', $quoteProduct->id)) {
                $quantity -= Quantity::toFloat($orderProduct->quantity);
            }
        }

        return Quantity::round($quantity);
    }
}
