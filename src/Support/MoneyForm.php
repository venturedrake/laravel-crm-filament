<?php

namespace VentureDrake\LaravelCrmFilament\Support;

use VentureDrake\LaravelCrm\Support\Money;

/**
 * The two directions money travels between a stored integer of cents and a
 * form field, in one place.
 *
 * Thin on purpose: the normalisation itself is core's {@see Money}, so the
 * masked-input and empty-string handling cannot drift between the two
 * packages. What this adds is the /100 and the null-preservation, which were
 * hand-rolled at roughly twenty call sites across the six Edit pages and the
 * Xero mirrors.
 */
class MoneyForm
{
    /**
     * Stored cents => the value a money form field shows. Null stays null:
     * "no amount" is not "zero".
     */
    public static function centsToForm(mixed $cents): ?float
    {
        if ($cents === null || $cents === '') {
            return null;
        }

        return round(Money::toFloat($cents) / 100, 2);
    }

    /**
     * A submitted money field => the integer of cents to store.
     */
    public static function formToCents(mixed $value): ?int
    {
        return Money::toInteger($value);
    }

    /**
     * Stored cents => a display string with two decimal places, or $placeholder
     * when there is no amount.
     */
    public static function format(mixed $cents, ?string $placeholder = null): ?string
    {
        $value = self::centsToForm($cents);

        return $value === null ? $placeholder : number_format($value, 2);
    }
}
