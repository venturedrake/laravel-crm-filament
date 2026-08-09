<?php

namespace VentureDrake\LaravelCrmFilament\Support;

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use VentureDrake\LaravelCrm\Models\Role;
use VentureDrake\LaravelCrm\Models\UserInvitation;
use VentureDrake\LaravelCrmFilament\Resources\Users\UserResource;

/**
 * The accept-invitation logic, lifted from core's
 * `UserController::showAcceptInvite()` / `acceptInvite()` / `invitationRole()`
 * and re-pointed at the panel.
 *
 * It lives here rather than on the page so the GET and the POST share one
 * implementation and the branching is unit-testable without a browser.
 *
 * Deltas from core, all forced by the panel-only context:
 *  - the host user is resolved through UserResource::getModel(), not the
 *    `use App\User` at the top of UserController (whose alias only fires when
 *    App\Models\User happens to exist);
 *  - a logged-out invitee with an existing account is sent to the *panel's*
 *    login URL, not `route('laravel-crm.login')`, which is gated off;
 *  - the team_user / current_team_id blocks are dropped — the plugin is
 *    single-tenant (see TenancyGuard).
 *
 * `invitationRole()` is copied verbatim from core (it is protected there, so
 * it cannot be inherited). It is the fail-closed check that stops an
 * invitation minted before the Owner guard existed — or written by any other
 * path — from being redeemed into an Owner.
 */
class UserInvitationAcceptor
{
    public const OUTCOME_INVALID = 'invalid';

    public const OUTCOME_LOGIN_REQUIRED = 'login_required';

    public const OUTCOME_WRONG_USER = 'wrong_user';

    public const OUTCOME_NEEDS_REGISTRATION = 'needs_registration';

    public const OUTCOME_ACCEPTED = 'accepted';

    /**
     * The invitation for $code, or null when it is unknown, revoked,
     * already accepted or expired.
     */
    public static function find(?string $code): ?UserInvitation
    {
        if ($code === null || $code === '') {
            return null;
        }

        $invitation = UserInvitation::query()->where('code', $code)->first();

        return $invitation && $invitation->isValid() ? $invitation : null;
    }

    /**
     * Decide what the visitor should see for $invitation, without writing
     * anything. Mirrors core's three-branch showAcceptInvite().
     *
     * @return array{outcome: string, invitation: ?UserInvitation, user: ?Model, url: ?string}
     */
    public static function inspect(?UserInvitation $invitation): array
    {
        if ($invitation === null) {
            return ['outcome' => self::OUTCOME_INVALID, 'invitation' => null, 'user' => null, 'url' => null];
        }

        $existing = self::findHostUser($invitation->email);

        if ($existing === null) {
            return ['outcome' => self::OUTCOME_NEEDS_REGISTRATION, 'invitation' => $invitation, 'user' => null, 'url' => null];
        }

        if (! auth()->check()) {
            return [
                'outcome' => self::OUTCOME_LOGIN_REQUIRED,
                'invitation' => $invitation,
                'user' => $existing,
                'url' => self::loginUrlFor($invitation),
            ];
        }

        if (! self::sameEmail(auth()->user()?->email, $invitation->email)) {
            return ['outcome' => self::OUTCOME_WRONG_USER, 'invitation' => $invitation, 'user' => $existing, 'url' => null];
        }

        return ['outcome' => self::OUTCOME_ACCEPTED, 'invitation' => $invitation, 'user' => $existing, 'url' => null];
    }

    /**
     * Redeem $invitation for a host user that already exists.
     *
     * Grants CRM access, swaps in the invitation's role and stamps
     * accepted_at, all in one transaction so a mid-way failure rolls back.
     */
    public static function acceptExistingUser(UserInvitation $invitation, Model $user): Model
    {
        DB::transaction(function () use ($invitation, $user) {
            $user->forceFill(['crm_access' => 1])->save();

            if ($role = self::invitationRole($invitation)) {
                if ($removeRole = $user->roles()->where('crm_role', 1)->first()) {
                    $user->removeRole($removeRole);
                }

                $user->assignRole($role);
            }

            $invitation->forceFill(['accepted_at' => now()])->save();
        });

        return $user;
    }

    /**
     * Create a brand-new host user from the accept form, redeem the
     * invitation and log them in.
     *
     * @param  array<string, mixed>  $data  name, password, password_confirmation
     *
     * @throws ValidationException
     */
    public static function acceptNewUser(UserInvitation $invitation, array $data): Model
    {
        $validated = validator($data, [
            'name' => 'required|string|max:255',
            'password' => 'required|string|min:8|confirmed',
        ])->validate();

        $model = self::userModel();

        $user = DB::transaction(function () use ($invitation, $validated, $model) {
            $user = $model::forceCreate([
                'name' => $validated['name'],
                'email' => $invitation->email,
                'password' => Hash::make($validated['password']),
                'crm_access' => 1,
            ]);

            if ($role = self::invitationRole($invitation)) {
                $user->assignRole($role);
            }

            $invitation->forceFill(['accepted_at' => now()])->save();

            return $user;
        });

        Auth::login($user);

        return $user;
    }

    /**
     * Resolve the CRM role an invitation may actually confer.
     *
     * Copied verbatim from core's UserController::invitationRole() — it is
     * protected there, so it cannot be inherited, and it is the single most
     * important line of this whole flow. UserInvite only offers Owner to an
     * existing Owner, but an invitation minted before that guard existed must
     * not be redeemable into an Owner either. The entitlement that matters at
     * redemption time is the *inviter's*, so it is re-checked against
     * invited_by.
     *
     * An inviter that can no longer be verified as an Owner is treated as not
     * entitled and the role is dropped: the invitee still gets CRM access and
     * an Owner can set their role afterwards. Failing closed here is cheap;
     * silently minting an Owner is not.
     */
    public static function invitationRole(UserInvitation $invitation): ?Role
    {
        if (! $invitation->role_id || ! $role = Role::find($invitation->role_id)) {
            return null;
        }

        if ($role->name === 'Owner') {
            $model = self::userModel();
            $inviter = $invitation->invited_by ? $model::find($invitation->invited_by) : null;

            if (! $inviter || ! $inviter->hasRole('Owner')) {
                return null;
            }
        }

        return $role;
    }

    /**
     * The panel login URL, carrying the invitation code so a post-login flow
     * can resume acceptance. Core sends people to `laravel-crm.login`, which
     * is gated off on a panel-only install.
     */
    public static function loginUrlFor(UserInvitation $invitation): string
    {
        $login = Filament::getCurrentOrDefaultPanel()?->getLoginUrl() ?? url('/');

        return $login . (str_contains($login, '?') ? '&' : '?') . 'invitation=' . urlencode((string) $invitation->code);
    }

    /**
     * The host user owning $email, matched case-insensitively — an invitation
     * for Jane@example.com must find jane@example.com.
     */
    public static function findHostUser(string $email): ?Model
    {
        $model = self::userModel();

        return $model::query()->whereRaw('LOWER(email) = ?', [strtolower($email)])->first();
    }

    public static function sameEmail(?string $a, ?string $b): bool
    {
        return $a !== null && $b !== null && strtolower($a) === strtolower($b);
    }

    /**
     * @throws AccessDeniedHttpException
     */
    public static function assertSameUser(UserInvitation $invitation): void
    {
        if (! self::sameEmail(auth()->user()?->email, $invitation->email)) {
            throw new AccessDeniedHttpException(trans('laravel-crm::lang.user_invitation_wrong_user', [
                'email' => $invitation->email,
            ]));
        }
    }

    /**
     * @return class-string<Model>
     */
    protected static function userModel(): string
    {
        /** @var class-string<Model> $model */
        $model = UserResource::getModel();

        return $model;
    }
}
