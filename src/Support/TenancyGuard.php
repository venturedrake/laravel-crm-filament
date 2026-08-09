<?php

namespace VentureDrake\LaravelCrmFilament\Support;

use Illuminate\Support\Facades\Log;
use VentureDrake\LaravelCrmFilament\Console\InstallCommand;

/**
 * This plugin does not support core's multi-tenancy (`laravel-crm.teams`).
 *
 * Core's BelongsToTeamsScope would scope the CRM models the plugin's resources
 * are built on for free. The exposure is everything *outside* those models,
 * and there is a lot of it: the Spatie `roles` / `permissions` queries, the
 * host `users` table, `Team::query()` on UserResource, and — decisively —
 * there is not one `Filament::getTenant()` call anywhere in this package. A
 * panel that half-scopes is worse than one that says plainly it does not
 * scope: it looks tenanted right up to the row that isn't.
 *
 * So the posture is: refuse at install, warn at runtime, implement nothing.
 *
 *  - {@see InstallCommand} hard-stops
 *    when teams are on, unless `--allow-teams` is passed. Install is
 *    interactive and reversible, so that is where a refusal belongs.
 *  - The service provider warns and never throws. Throwing in a provider would
 *    break `config:cache`, `migrate` and `queue:work` and would brick a host
 *    that flipped the flag after installing.
 *  - {@see LaravelCrmPlugin::allowUnsupportedTenancy()} silences the warning,
 *    so running anyway is a recorded decision rather than a missed banner.
 *
 * Note this is `laravel-crm.teams` (multi-tenancy), *not* the `teams` entry in
 * `config('laravel-crm.modules')` — that is the CRM teams module, a grouping
 * entity, which the plugin supports and gates CrmTeamResource behind.
 */
class TenancyGuard
{
    protected static bool $warned = false;

    /**
     * Whether the host has core's multi-tenancy turned on.
     */
    public static function isEnabled(): bool
    {
        return (bool) config('laravel-crm.teams');
    }

    /**
     * Whether the plugin should complain: teams are on and nobody has
     * acknowledged that the panel is not tenant-aware.
     */
    public static function shouldWarn(bool $acknowledged = false): bool
    {
        return self::isEnabled() && ! $acknowledged;
    }

    /**
     * The one-line explanation, shared by the console warning, the render-hook
     * banner and the installer's refusal so they cannot drift.
     */
    public static function message(): string
    {
        return 'laravel-crm.teams is enabled, but the Filament CRM panel is not tenant-aware: '
            . 'its role, permission and user queries are not team-scoped. Use core\'s own '
            . '/crm UI for multi-tenant installs, or call '
            . 'LaravelCrmPlugin::make()->allowUnsupportedTenancy() to acknowledge and silence this.';
    }

    /**
     * Log the warning at most once per process — a provider boots on every
     * request and every queued job.
     */
    public static function warnOnce(bool $acknowledged = false): void
    {
        if (self::$warned || ! self::shouldWarn($acknowledged)) {
            return;
        }

        self::$warned = true;

        Log::warning('[laravel-crm-filament] ' . self::message());
    }

    /**
     * For tests.
     */
    public static function forgetWarning(): void
    {
        self::$warned = false;
    }
}
