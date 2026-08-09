<?php

namespace VentureDrake\LaravelCrmFilament\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Throwable;

/**
 * Turns a policy that throws on an un-seeded permission into a 403.
 *
 * Core 2.4.0 registers ActivityPolicy and ProductAttributePolicy for the first
 * time. Before that no policy resolved for those models, and Filament allows
 * when it finds none — so ProductAttributeResource was open to every panel
 * user, and now enforces four permissions.
 *
 * Both policies call `$user->hasPermissionTo(...)` unguarded, which throws
 * PermissionDoesNotExist when the host has never re-seeded (and a
 * QueryException when the Spatie tables are absent). Filament goes straight to
 * the Gate for a policied model, so ChecksCrmPermissions — which catches
 * exactly those two — never gets a look in. The difference this makes is
 * between "a menu item disappears until you run a command" and "the CRM 500s
 * after upgrade".
 *
 * Note this is fail-*closed*: an install missing the permission rows denies,
 * it does not open the resource. That is the opposite of ChecksCrmPermissions,
 * and deliberately so — that trait guards settings pages, this one guards a
 * resource core has decided should be permissioned.
 *
 * Upstream fix (recommended): have both policies try/catch, the way
 * FeatureService::isAdminCommenter() already does.
 */
trait GuardsPoliciedResource
{
    public static function canViewAny(): bool
    {
        return static::guardedPolicyCheck(fn (): bool => parent::canViewAny());
    }

    public static function canView(Model $record): bool
    {
        return static::guardedPolicyCheck(fn (): bool => parent::canView($record));
    }

    public static function canCreate(): bool
    {
        return static::guardedPolicyCheck(fn (): bool => parent::canCreate());
    }

    public static function canEdit(Model $record): bool
    {
        return static::guardedPolicyCheck(fn (): bool => parent::canEdit($record));
    }

    public static function canDelete(Model $record): bool
    {
        return static::guardedPolicyCheck(fn (): bool => parent::canDelete($record));
    }

    protected static function guardedPolicyCheck(callable $check): bool
    {
        try {
            return (bool) $check();
        } catch (PermissionDoesNotExist | QueryException) {
            return false;
        } catch (Throwable $e) {
            // Anything else is a real fault — a mismatched guard, a bug in a
            // custom user model — and must not be swallowed.
            throw $e;
        }
    }
}
