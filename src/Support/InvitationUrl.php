<?php

namespace VentureDrake\LaravelCrmFilament\Support;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Throwable;
use VentureDrake\LaravelCrm\Models\UserInvitation;
use VentureDrake\LaravelCrmFilament\Pages\AcceptInvitation;

/**
 * Resolves the accept-invitation link for a {@see UserInvitation}.
 *
 * Core's acceptance surface is unreachable from a panel-only install.
 * `LaravelCrmServiceProvider` only calls `loadRoutesFrom(Http/routes.php)`
 * while `laravel-crm.user_interface` is on, and both accept routes live in
 * that file — but the plugin's own installer appends
 * `LARAVEL_CRM_USER_INTERFACE=false` so the Filament panel can own `/crm`.
 * In that (recommended) configuration
 * `route('laravel-crm.users.invitations.accept')` throws
 * RouteNotFoundException from inside a queued notification: the admin sees
 * "invitation sent" and the invitee never hears anything.
 *
 * So the plugin owns its own acceptance page ({@see AcceptInvitation}) and
 * resolves through it first, falling back to core's route when the host has
 * kept the base UI on, and to null when neither exists. Same convention as
 * {@see PortalUrl} — route-guard, never hard-code.
 *
 * Upstream fix (recommended): move the two `users/invitations/accept/{code}`
 * routes out of the `user_interface` gate in
 * `LaravelCrmServiceProvider::registerRoutes()`, since an invitation is not
 * part of the optional web UI — it is the only way into the CRM for a new
 * user regardless of which front end is serving it.
 */
class InvitationUrl
{
    /**
     * Core's acceptance route, registered only when
     * `laravel-crm.user_interface` is on.
     */
    public const CORE_ROUTE = 'laravel-crm.users.invitations.accept';

    /**
     * The link to mail an invitee, or null when neither the panel nor base
     * CRM exposes an acceptance route.
     */
    public static function acceptUrl(UserInvitation $invitation): ?string
    {
        $code = (string) $invitation->code;

        if ($panelRoute = self::panelRouteName()) {
            return route($panelRoute, ['code' => $code]);
        }

        if (Route::has(self::CORE_ROUTE)) {
            return route(self::CORE_ROUTE, ['code' => $code]);
        }

        return null;
    }

    /**
     * Whether an invitation can produce a working link at all. Used as the
     * invite action's pre-flight, so an invitation is never minted that
     * nobody could redeem.
     */
    public static function exists(): bool
    {
        return self::panelRouteName() !== null || Route::has(self::CORE_ROUTE);
    }

    /**
     * The acceptance route of the first panel that registers one, or null.
     *
     * Filament prefixes panel routes with `filament.{panelId}.`, so the name
     * depends on which panel the plugin is installed into — and a queued
     * notification has no current panel. Iterating every registered panel is
     * the same approach SendUserInvite::panelWithPasswordReset() takes.
     */
    public static function panelRouteName(): ?string
    {
        try {
            $panels = Filament::getPanels();
        } catch (Throwable) {
            // Filament is not booted (a queue worker without a panel context).
            return null;
        }

        $current = null;

        try {
            $current = Filament::getCurrentOrDefaultPanel();
        } catch (Throwable) {
            // No default panel; fall through to the full sweep.
        }

        foreach ($current ? [$current, ...array_values($panels)] : $panels as $panel) {
            $name = AcceptInvitation::routeNameFor($panel);

            if (Route::has($name)) {
                return $name;
            }
        }

        return null;
    }
}
