<?php

namespace VentureDrake\LaravelCrmFilament\Support;

use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\ProductVariation;

/**
 * The variation select on a line-item row, and the price it sets.
 *
 * Mirrors core 2.5.0's Livewire `HasVariationOptions` trait, which is bound
 * to a Livewire component's `$this` and so cannot be called from Filament's
 * static form closures.
 *
 * A product with no active variations has no options, so the row renders
 * exactly as it did before variations existed. What a line may actually store
 * is decided by core's services (`ResolvesProductVariation`), which drop a
 * variation of another product or team; this only keeps the select honest.
 */
class LineItemVariation
{
    /**
     * The product's active variations, in order, as `id => label`.
     *
     * A line already on a variation that has since been deactivated or
     * deleted keeps it in its own options, so the select still shows what the
     * line holds. Team scoping applies through the model's global scope.
     *
     * @return array<int, string>
     */
    public static function options(mixed $productId, mixed $currentVariationId = null): array
    {
        if (! is_numeric($productId)) {
            return [];
        }

        $options = ProductVariation::query()
            ->where('product_id', (int) $productId)
            ->active()
            ->ordered()
            ->get()
            ->mapWithKeys(fn (ProductVariation $variation) => [$variation->id => self::label($variation)])
            ->all();

        if (is_numeric($currentVariationId) && ! array_key_exists((int) $currentVariationId, $options)) {
            if ($current = self::resolve($productId, $currentVariationId)) {
                $options[$current->id] = self::label($current);
            }
        }

        return $options;
    }

    /**
     * A submitted variation id resolved against the row's product. Null when
     * blank, or when it names a variation of another product or team.
     *
     * Deleted variations still resolve, so a line already on one keeps it
     * (and its price) while other fields are edited.
     */
    public static function resolve(mixed $productId, mixed $variationId): ?ProductVariation
    {
        if (! is_numeric($productId) || ! is_numeric($variationId)) {
            return null;
        }

        return ProductVariation::withTrashed()
            ->where('product_id', (int) $productId)
            ->whereKey((int) $variationId)
            ->first();
    }

    /**
     * The unit price, in major units, for a line of this product and
     * variation in $currency: the variation's own price in that currency,
     * else the product's default price ({@see Product::unitPriceFor()}).
     *
     * Null when the product does not exist, so a caller can leave the price
     * alone; 0.0 when it exists but has no price.
     */
    public static function unitPrice(mixed $productId, mixed $variationId, ?string $currency): ?float
    {
        if (! is_numeric($productId) || ! $product = Product::withTrashed()->find((int) $productId)) {
            return null;
        }

        $cents = $product->unitPriceFor(self::resolve($product->id, $variationId), filled($currency) ? $currency : null);

        return MoneyForm::centsToForm($cents) ?? 0.0;
    }

    /**
     * The supplier unit price, in major units, for a purchase-order line of
     * this product and variation: the product's cost_per_unit, else the unit
     * price a sale line would use (the variation's own in $currency, else
     * the product's default), else $fallbackCents.
     *
     * The cost always comes from the product's default price: core only
     * writes unit_price to a variation's price row, so a variation row never
     * carries a cost_per_unit.
     */
    public static function supplierUnitPrice(mixed $productId, mixed $variationId, ?string $currency, ?int $fallbackCents): float
    {
        $product = is_numeric($productId) ? Product::withTrashed()->find((int) $productId) : null;
        $productPrice = $product?->getDefaultPrice();

        if ($productPrice && $productPrice->cost_per_unit) {
            return $productPrice->cost_per_unit / 100;
        }

        $price = ($product ? self::resolve($product->id, $variationId)?->priceFor(filled($currency) ? $currency : null) : null)
            ?? $productPrice;

        if ($price && $price->unit_price) {
            return $price->unit_price / 100;
        }

        return $fallbackCents !== null ? $fallbackCents / 100 : 0;
    }

    public static function label(ProductVariation $variation): string
    {
        return $variation->code ? $variation->name . ' (' . $variation->code . ')' : (string) $variation->name;
    }
}
