<?php

namespace VentureDrake\LaravelCrmFilament\Support;

use Closure;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;

/**
 * The one way this plugin renders a stored money column.
 *
 * Filament's ->money() takes a $divideBy that defaults to 0 — falsy, so it
 * never divides — which means handing it a column stored in minor units
 * renders 100x too large. Before this class there were five ad-hoc patterns
 * competing to work around that (divideBy: 100, ->state(... / 100),
 * ->state(MoneyForm::centsToForm()), number_format(), and plain ->money() that
 * simply stayed wrong), so the same amount rendered differently depending on
 * which page you were on.
 *
 * Both factories format through {@see MoneyForm::display()}, i.e. through the
 * package's own money() helper, so a value shown at /admin matches /crm
 * exactly — except for a currency money() cannot resolve, which display()
 * degrades to a plain amount rather than letting the throw take out the row's
 * whole page.
 *
 * Use this for any column that carries its own currency. A stored amount with
 * no currency to name it (the Xero item mirror) is better off as a bare
 * {@see MoneyForm::format()} than labelled with a default that is a guess.
 */
class CrmMoney
{
    /**
     * A table column rendering $name, a column of stored cents.
     *
     * $currency is either the name of the attribute holding the currency code
     * ('currency_code' for the Xero mirrors), or a closure resolving it from
     * the record (Product reads it off the default price). It defaults to the
     * record's own `currency`, and falls back to the configured default
     * currency when there is none.
     */
    public static function column(string $name, string | Closure | null $currency = null): TextColumn
    {
        return TextColumn::make($name)
            ->formatStateUsing(fn ($state, $record) => MoneyForm::display($state, static::resolveCurrency($currency, $record)));
    }

    /**
     * An infolist entry rendering $name, a column of stored cents. Same
     * $currency contract as {@see column()}.
     */
    public static function entry(string $name, string | Closure | null $currency = null): TextEntry
    {
        return TextEntry::make($name)
            ->formatStateUsing(fn ($state, $record) => MoneyForm::display($state, static::resolveCurrency($currency, $record)));
    }

    protected static function resolveCurrency(string | Closure | null $currency, mixed $record): ?string
    {
        if ($currency instanceof Closure) {
            return ($currency)($record) ?: null;
        }

        return $record?->{$currency ?? 'currency'} ?: null;
    }
}
