<?php

namespace VentureDrake\LaravelCrmFilament\Support;

use Illuminate\Support\Facades\Gate;
use VentureDrake\LaravelCrmFilament\Resources\Users\UserResource;

/**
 * Authorization for the user-management surfaces (invite, invitations list,
 * resend, revoke).
 *
 * Core's UserPolicy is registered against the *literal strings* `App\User`
 * and `App\Models\User` (LaravelCrmServiceProvider::$policies), so on a host
 * whose user model lives anywhere else — a `Domain\Users\User`, an extended
 * model in another namespace, this package's own test stub — Gate resolves no
 * policy, and Gate::allows() denies when there is neither a policy nor an
 * ability callback. Going straight to the Gate would therefore hide user
 * management from exactly the hosts that configured it most deliberately.
 *
 * So: prefer the policy when one actually resolves, and otherwise check the
 * permission the policy itself would have checked. This is *not* the fail-open
 * ChecksCrmPermissions trait — a missing permission row denies here.
 *
 * Upstream fix (recommended): have core register UserPolicy against
 * `config('auth.providers.users.model')` as well as the two literals, so the
 * Gate is authoritative on every host and this fallback can be dropped.
 */
class UserGate
{
    /**
     * Whether the current user may create users — core's UserPolicy::create()
     * and the `create crm users` permission it checks.
     */
    public static function canCreateUsers(): bool
    {
        return self::allows('create', 'create crm users');
    }

    public static function allows(string $ability, string $permission): bool
    {
        $model = UserResource::getModel();

        if (Gate::getPolicyFor($model) !== null) {
            return Gate::allows($ability, $model);
        }

        return (bool) auth()->user()?->can($permission);
    }
}
