<?php

namespace VentureDrake\LaravelCrmFilament\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;

/**
 * Resolves public portal links through the base package's named routes
 * instead of hard-coding the `p/…` prefix.
 *
 * laravel-crm only registers a subset of the portal routes (quotes,
 * invoices, purchase-orders, features), and the prefix itself is owned by
 * the base package, so a hard-coded `p/orders/…` link is a guaranteed
 * 404. Call sites resolve the URL with {@see self::for()} — null when the
 * route is missing — and gate their visibility with {@see self::exists()}.
 *
 * Upstream recommendation: laravel-crm should register
 * `p/orders/{order:external_id}` as `laravel-crm.portal.orders.show` and
 * `p/deliveries/{delivery:external_id}` as `laravel-crm.portal.deliveries.show`.
 * Until it does, the Order and Delivery preview actions stay hidden — a
 * plugin can only route-guard, not add the base package's portal pages.
 */
class PortalUrl
{
    /**
     * Build the portal URL for a record, or null when the base package
     * does not register the route.
     */
    public static function for(string $routeName, Model $record): ?string
    {
        if (! self::exists($routeName)) {
            return null;
        }

        return route($routeName, self::portalKey($record));
    }

    /**
     * Whether the base package registers the given portal route. Intended
     * for `->visible()` so an action hides rather than rendering a dead link.
     */
    public static function exists(string $routeName): bool
    {
        return Route::has($routeName);
    }

    /**
     * Portal routes are keyed by `external_id`; fall back to the route key
     * for models that do not expose one.
     */
    protected static function portalKey(Model $record): mixed
    {
        return $record->getAttribute('external_id') ?? $record->getRouteKey();
    }
}
