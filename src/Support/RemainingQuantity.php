<?php

namespace VentureDrake\LaravelCrmFilament\Support;

use Closure;
use Filament\Schemas\Components\Utilities\Get;
use VentureDrake\LaravelCrm\Http\Rules\QuantityWithinRemaining;
use VentureDrake\LaravelCrm\Models\OrderProduct;
use VentureDrake\LaravelCrm\Support\Quantity;

/**
 * How much of an order line is still outstanding, and a Filament-shaped rule
 * that refuses to draw down more than that.
 *
 * Core ships {@see QuantityWithinRemaining},
 * and being a plain ValidationRule it drops straight into `->rules([])` — but
 * its constructor finds the row it is validating with
 * `explode('.', $attribute)[1]`. Inside a Filament Repeater the attribute is
 * `data.products.<uuid>.quantity`, so index [1] is the literal string
 * `products`, the row lookup misses, and the rule silently passes everything.
 * A rule that always passes is worse than no rule, because it looks like a
 * guard in a diff.
 *
 * Bending the attribute path to suit core's parser would mean fighting
 * Filament's state paths on every form. Instead this runs the same query core's
 * rule runs — sum(quantity) over the drawdown model, scoped by whereHas() on
 * the parent so a soft-deleted invoice or delivery does not hold quantity
 * hostage, excluding the row being edited — and reuses core's
 * `quantity_exceeds_remaining` message so both UIs say the same sentence.
 *
 * There is deliberately no hidden `quantity_max` field. Core's rule docblock
 * explains why: a value that round-trips through the browser is not a cap.
 *
 * Upstream fix (recommended): let QuantityWithinRemaining take the row
 * directly (or the row key) instead of deriving an index from the attribute
 * path, so any front end can use it.
 */
class RemainingQuantity
{
    /**
     * What is left on $orderProduct after everything already drawn against it.
     *
     * @param  class-string  $drawdownModel  InvoiceLine or DeliveryProduct
     * @param  string  $drawdownRelation  the parent relation on that model
     * @param  int|string|null  $ignoreKey  the drawdown row being edited, whose
     *                                      own quantity must not count against
     *                                      itself
     */
    public static function forOrderProduct(
        OrderProduct $orderProduct,
        string $drawdownModel,
        string $drawdownRelation,
        int | string | null $ignoreKey = null,
    ): float {
        $drawn = $drawdownModel::query()
            ->where('order_product_id', $orderProduct->id)
            ->whereHas($drawdownRelation)
            ->when($ignoreKey, fn ($query) => $query->whereKeyNot($ignoreKey))
            ->sum('quantity');

        return max(0.0, Quantity::round(
            Quantity::toFloat($orderProduct->quantity) - Quantity::toFloat($drawn)
        ));
    }

    /**
     * The remainder for a repeater row, or null when the row draws nothing
     * down and so has no cap.
     *
     * @param  class-string|null  $drawdownModel  null on the forms that draw
     *                                            nothing down (quote, order,
     *                                            purchase order, deal), where
     *                                            this is a no-op
     */
    public static function forRow(
        ?array $row,
        ?string $drawdownModel,
        ?string $drawdownRelation,
        ?string $drawdownKey,
    ): ?float {
        if ($drawdownModel === null || empty($row['order_product_id'])) {
            return null;
        }

        if (! $orderProduct = OrderProduct::find($row['order_product_id'])) {
            return null;
        }

        return self::forOrderProduct(
            $orderProduct,
            $drawdownModel,
            $drawdownRelation ?? '',
            $drawdownKey && ! empty($row[$drawdownKey]) ? $row[$drawdownKey] : null,
        );
    }

    /**
     * A closure rule for a Repeater's quantity field.
     *
     * Reads the sibling `order_product_id` / drawdown key through Filament's
     * $get rather than parsing the attribute path, which is exactly the part
     * core's rule cannot do.
     */
    public static function rule(?string $drawdownModel, ?string $drawdownRelation, ?string $drawdownKey): Closure
    {
        return static function (Get $get) use ($drawdownModel, $drawdownRelation, $drawdownKey): Closure {
            return static function (string $attribute, mixed $value, Closure $fail) use ($get, $drawdownModel, $drawdownRelation, $drawdownKey): void {
                if ($value === null || $value === '') {
                    return;
                }

                $max = self::forRow([
                    'order_product_id' => $get('order_product_id'),
                    $drawdownKey => $drawdownKey ? $get($drawdownKey) : null,
                ], $drawdownModel, $drawdownRelation, $drawdownKey);

                if ($max === null) {
                    return;
                }

                if (Quantity::greaterThan($value, $max)) {
                    $fail(ucfirst(__('laravel-crm::lang.quantity_exceeds_remaining', [
                        'remaining' => Quantity::format($max),
                    ])));
                }
            };
        };
    }

    /**
     * Mirrors core's ModelProducts::clampQuantity() — the UX half. The server
     * rule above is the guard; this stops an operator typing a number that was
     * never going to be accepted.
     */
    public static function clamp(mixed $state, ?float $max): mixed
    {
        if ($max === null || $state === null || $state === '') {
            return $state;
        }

        return Quantity::greaterThan($state, $max) ? Quantity::round($max) : $state;
    }
}
