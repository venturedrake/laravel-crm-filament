<?php

namespace VentureDrake\LaravelCrmFilament\Concerns;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Throwable;

/**
 * Resolves a core CRM Spatie permission check for the authenticated user.
 *
 * Shared by the settings pages (@see AuthorizesCrmSettingsPage) and by the
 * relation managers whose related model has no policy in core CRM, so both
 * degrade the same way on hosts that never seeded a given permission.
 */
trait ChecksCrmPermissions
{
    /**
     * Permissions already warned about this process.
     *
     * @var array<string, true>
     */
    protected static array $warnedPermissions = [];

    /**
     * Resolve a Spatie permission check for the authenticated user.
     *
     * Degrades gracefully rather than hard-failing: `hasPermissionTo()` throws
     * `PermissionDoesNotExist` when the host has never seeded the permission
     * (and a `QueryException` when the Spatie tables are absent altogether).
     * In both cases the permission simply is not part of the host's install,
     * so nobody could ever hold it — we fall back to the pre-gating behaviour
     * of allowing access instead of locking every user out.
     * A user who is merely missing a permission that *does* exist gets `false`.
     *
     * The catch is deliberately narrow: those two exceptions mean "this
     * permission is not part of the install". Anything else — a mismatched
     * guard, a bug in a custom user model — is a real fault and must not
     * silently open the settings pages, so it propagates.
     */
    protected static function userHasCrmPermission(?string $permission): bool
    {
        if (blank($permission)) {
            return true;
        }

        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        try {
            if (method_exists($user, 'hasPermissionTo')) {
                return (bool) $user->hasPermissionTo($permission);
            }

            return (bool) $user->can($permission);
        } catch (PermissionDoesNotExist | QueryException $e) {
            // Fail open, but never silently: an install that is missing CRM
            // permissions looks exactly like one that is correctly configured
            // until somebody reads a log line.
            self::warnAboutMissingPermission($permission, $e);

            return true;
        }
    }

    /**
     * Memoised per process — a settings page can check the same permission
     * many times per request, and a warning per check is noise, not signal.
     */
    protected static function warnAboutMissingPermission(string $permission, Throwable $e): void
    {
        if (isset(self::$warnedPermissions[$permission])) {
            return;
        }

        self::$warnedPermissions[$permission] = true;

        Log::warning('[laravel-crm-filament] Allowing access because the CRM permission "' . $permission . '" is not present in this install. Re-seed the CRM permissions (php artisan laravelcrm:update) to enforce it.', [
            'exception' => $e::class,
        ]);
    }

    /**
     * For tests.
     */
    public static function forgetPermissionWarnings(): void
    {
        self::$warnedPermissions = [];
    }
}
