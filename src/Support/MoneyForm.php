<?php

namespace VentureDrake\LaravelCrmFilament\Support;

use VentureDrake\LaravelCrm\Support\Money;

/**
 * The three directions money travels between a stored integer of cents, a
 * form field, and a rendered string, in one place.
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
     * Stored cents => the string the package itself renders.
     *
     * Filament's own ->money() cannot do this: its $divideBy argument defaults
     * to 0, which is falsy, so it never divides and renders stored cents 100x
     * too large. Routing through cknow's money() — the helper every core Blade
     * view, PDF and mail template uses — is what keeps /admin byte-identical
     * to /crm.
     *
     * money() throws UnknownCurrencyException on anything that is not an ISO
     * code, and `currency` is a free-text field in several places (the Product
     * form and the line-item repeater both accept any three characters). A
     * throw here would escape from inside a table row or infolist entry and
     * take the whole page down, where Filament's ->money() merely rendered the
     * row oddly, so an unresolvable currency degrades to the plain amount
     * followed by whatever code was stored.
     */
    public static function display(mixed $cents, ?string $currency = null): ?string
    {
        if ($cents === null || $cents === '') {
            return null;
        }

        $amount = (int) round(Money::toFloat($cents));
        $currency = $currency ?: config('laravel-crm.default_currency', 'USD');

        try {
            return (string) money($amount, $currency);
        } catch (\Throwable $e) {
            return trim(number_format($amount / 100, 2) . ' ' . $currency);
        }
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
