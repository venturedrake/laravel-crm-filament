<?php

namespace VentureDrake\LaravelCrmFilament\Support;

use VentureDrake\LaravelCrm\Models\Delivery;
use VentureDrake\LaravelCrm\Models\DeliveryProduct;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\InvoiceLine;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Support\Quantity;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\LineItemsRepeater;

/**
 * The line-item payload for converting an Order into something that draws
 * down against it.
 *
 * Two things this centralises:
 *
 *  - The prefill is the *remainder*, not the full ordered quantity. Order 10,
 *    invoice 6, then reopen Order → Invoice: offering 10 again invites an
 *    operator to accept a default the server will reject
 *    ({@see RemainingQuantity}) — or, before that rule existed, to
 *    over-invoice silently.
 *  - There was one copy of this arithmetic per action *and* a second static
 *    copy on OrderResource for the table row action. Two copies of drawdown
 *    maths is how two surfaces come to disagree.
 *
 * Where core's prefill uses `->first()` but its validation rule uses `sum()`,
 * this follows `sum()`: matching the guard beats matching the bug.
 *
 * Lines with nothing outstanding are dropped entirely rather than prefilled at
 * zero — a zero-quantity line is noise on an invoice.
 */
class OrderDrawdownPrefill
{
    /**
     * Invoice lines for $order, each carrying the remaining quantity.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function invoiceProducts(Order $order): array
    {
        return self::products($order, InvoiceLine::class, 'invoice', function ($orderProduct, float $remaining): array {
            $unitPrice = MoneyForm::centsToForm($orderProduct->price) ?? 0.0;
            $amount = round($unitPrice * $remaining, 2);
            $rate = LineItemsRepeater::resolveTaxRate(
                $orderProduct->product_id !== null ? (int) $orderProduct->product_id : null
            );

            return [
                'id' => $orderProduct->product_id,
                'order_product_id' => $orderProduct->id,
                'quantity' => $remaining,
                'unit_price' => $unitPrice,
                'amount' => $amount,
                // Same rate chain InvoiceService will apply to this line when it
                // writes it, so the header totalsFor() derives cannot contradict
                // the lines that get stored.
                'tax_amount' => round($amount * $rate / 100, 2),
                'comments' => $orderProduct->comments,
            ];
        });
    }

    /**
     * Delivery lines for $order, each carrying the remaining quantity.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function deliveryProducts(Order $order): array
    {
        return self::products($order, DeliveryProduct::class, 'delivery', fn ($orderProduct, float $remaining): array => [
            'order_product_id' => $orderProduct->id,
            'quantity' => $remaining,
        ]);
    }

    /**
     * The header totals for an invoice built from $order's outstanding lines.
     *
     * Recomputed from the prefilled lines rather than copied off the order:
     * a part-invoiced order's own totals describe the whole order, so copying
     * them onto a partial invoice would state an amount the lines do not add
     * up to — exactly the mismatch CheckAmount flags.
     *
     * `total` is `sub_total + tax`, matching core's definition (lines - discount
     * + tax + adjustments) and LineItemsRepeater::recalcFormTotals(). Returning
     * the bare subtotal as the total understated every converted invoice by its
     * whole tax amount, which then flowed into amount_due, the PDF and the
     * portal.
     *
     * @param  array<int, array<string, mixed>>  $products
     * @return array{sub_total: float, tax: float, total: float}
     */
    public static function totalsFor(array $products): array
    {
        $subTotal = round(array_sum(array_map(
            fn (array $row): float => (float) ($row['amount'] ?? 0),
            $products,
        )), 2);

        $tax = round(array_sum(array_map(
            fn (array $row): float => (float) ($row['tax_amount'] ?? 0),
            $products,
        )), 2);

        return [
            'sub_total' => $subTotal,
            'tax' => $tax,
            'total' => round($subTotal + $tax, 2),
        ];
    }

    /**
     * Whether $order still has anything to draw down into $drawdownModel.
     *
     * @param  class-string  $drawdownModel
     */
    public static function hasRemaining(Order $order, string $drawdownModel, string $relation): bool
    {
        foreach ($order->orderProducts as $orderProduct) {
            if (Quantity::isPositive(RemainingQuantity::forOrderProduct($orderProduct, $drawdownModel, $relation))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  class-string<Invoice|Delivery>|class-string  $drawdownModel
     * @return array<int, array<string, mixed>>
     */
    protected static function products(Order $order, string $drawdownModel, string $relation, callable $shape): array
    {
        $products = [];

        foreach ($order->orderProducts as $orderProduct) {
            $remaining = RemainingQuantity::forOrderProduct($orderProduct, $drawdownModel, $relation);

            if (! Quantity::isPositive($remaining)) {
                continue;
            }

            $products[] = $shape($orderProduct, $remaining);
        }

        return $products;
    }
}
