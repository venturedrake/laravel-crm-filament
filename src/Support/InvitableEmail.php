<?php

namespace VentureDrake\LaravelCrmFilament\Support;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use VentureDrake\LaravelCrm\Http\Rules\AssignableRole;
use VentureDrake\LaravelCrm\Models\UserInvitation;
use VentureDrake\LaravelCrmFilament\Resources\Users\UserResource;

/**
 * Reject an email that cannot be invited: one that already belongs to a host
 * user, or one that already has an invitation outstanding.
 *
 * Core expresses this as an inline closure inside `UserInvite::rules()`. It is
 * a rule object here for the same reason {@see AssignableRole}
 * is one: Filament evaluates bare closures in `->rules()` as *its own*
 * closures (injecting `$get`, `$state`, …) rather than passing them through as
 * Laravel rules, so a closure would blow up on `$attribute`.
 *
 * The user lookup goes through UserResource::getModel(), never a hardcoded
 * App\Models\User, and matches case-insensitively — an invitation for
 * Jane@example.com must collide with jane@example.com.
 */
class InvitableEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $email = strtolower(trim((string) $value));

        if ($email === '') {
            return;
        }

        $model = UserResource::getModel();

        if ($model::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            $fail(ucfirst(__('laravel-crm::lang.user_invitation_email_taken')));

            return;
        }

        $pending = UserInvitation::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNull('accepted_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();

        if ($pending) {
            $fail(ucfirst(__('laravel-crm::lang.user_invitation_pending')));
        }
    }
}
