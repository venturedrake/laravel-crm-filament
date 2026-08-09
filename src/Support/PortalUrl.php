<?php

namespace VentureDrake\LaravelCrmFilament\Support;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/**
 * Resolves public portal links through the base package's named routes
 * instead of hard-coding the `p/…` prefix.
 *
 * As of core 2.4.0 laravel-crm registers three record portal routes — `quotes`,
 * `invoices` and `purchase-orders`
 * (vendor/venturedrake/laravel-crm/src/Http/portal-routes.php) — and the `p/`
 * prefix sits outside the CRM auth stack. There is still no
 * `laravel-crm.portal.orders.show` or `…deliveries.show`, so a hard-coded
 * `p/orders/…` link is a guaranteed 404. Call sites resolve the URL with
 * {@see self::for()} — null when the route is missing — and gate their
 * visibility with {@see self::exists()}.
 *
 * Upstream recommendation (partly landed): laravel-crm should also register
 * `p/orders/{order:external_id}` as `laravel-crm.portal.orders.show` and
 * `p/deliveries/{delivery:external_id}` as
 * `laravel-crm.portal.deliveries.show`. Until it does, those two preview
 * actions stay hidden — a plugin can only route-guard, not add the base
 * package's portal pages.
 *
 * All of them disappear when the host sets `laravel-crm.user_interface=false`,
 * because that unloads `portal-routes.php` along with the rest of the web UI —
 * and that is precisely the configuration this plugin's own installer
 * recommends. The same `exists()` gate covers that case.
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
     * A temporary signed portal URL for a record, or null when the base
     * package does not register the route. Keyed exactly as {@see self::for()}
     * so a mailed link and a previewed link can never diverge.
     */
    public static function temporarySignedFor(string $routeName, Model $record, DateTimeInterface $expiration): ?string
    {
        if (! self::exists($routeName)) {
            return null;
        }

        return URL::temporarySignedRoute($routeName, $expiration, self::portalKey($record));
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
