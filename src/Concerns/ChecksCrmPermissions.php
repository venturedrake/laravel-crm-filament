<?php

namespace VentureDrake\LaravelCrmFilament\Concerns;

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
     * Resolve a Spatie permission check for the authenticated user.
     *
     * Degrades gracefully rather than hard-failing: `hasPermissionTo()` throws
     * `PermissionDoesNotExist` when the host has never seeded the permission
     * (and a `QueryException` when the Spatie tables are absent altogether).
     * In both cases the permission simply is not part of the host's install,
     * so nobody could ever hold it — we fall back to the pre-gating behaviour
     * of allowing access instead of locking every user out.
     * A user who is merely missing a permission that *does* exist gets `false`.
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
        } catch (\Throwable) {
            return true;
        }
    }
}
