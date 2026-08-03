<?php

namespace VentureDrake\LaravelCrmFilament\Jobs;

use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Throwable;
use VentureDrake\LaravelCrm\Mail\WelcomeImportedUser;

/**
 * Host-agnostic wrapper around core CRM's `SendImportPasswordReset`.
 *
 * Core's job opens with `use App\Models\User;` and queries that class
 * directly, so it silently sends nothing on any host whose user model lives
 * somewhere else (a `Domain\Users\User`, an `App\User` on a Laravel 7-era
 * app, a package test stub, …). This job is otherwise identical — same
 * password-reset token, same `Mail\WelcomeImportedUser` mailable — but
 * resolves the model the host actually authenticates with,
 * `config('auth.providers.users.model')`, and resolves the set-password link
 * through {@see self::setPasswordUrlFor()} so it also works on the panel-only
 * installs the plugin's own installer recommends.
 *
 * Upstream fix (recommended, one line): replace core's `User::where(...)`
 * with `config('auth.providers.users.model')::where(...)` and drop the
 * `use App\Models\User;` import. This class can then become a thin subclass
 * or be dropped altogether.
 */
class SendUserInvite implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly string $email) {}

    public function handle(): void
    {
        $model = static::userModel();

        if ($model === null) {
            return;
        }

        $user = $model::query()->where('email', $this->email)->first();

        // CanResetPassword is what `Password::createToken()` needs; every user
        // model extending Illuminate\Foundation\Auth\User implements it.
        if (! $user instanceof Model || ! $user instanceof CanResetPassword) {
            return;
        }

        $setPasswordUrl = static::setPasswordUrlFor($user);

        // No reachable reset route means the invitee would receive a mail with
        // a dead link. Bail loudly in the log rather than mailing something
        // unusable — the action pre-flights the same check before it ever
        // creates the account, so this is the queue-side backstop.
        if ($setPasswordUrl === null) {
            Log::warning('[laravel-crm-filament] Skipped the user invite mail: no password-reset route is registered. Enable Filament\'s ->passwordReset() on the CRM panel, or set laravel-crm.user_interface=true.', [
                'email' => $this->email,
            ]);

            return;
        }

        Mail::send(new WelcomeImportedUser(
            name: (string) $user->getAttribute('name'),
            recipientEmail: (string) $user->getAttribute('email'),
            setPasswordUrl: $setPasswordUrl,
        ));
    }

    /**
     * The set-password link for an invited user, or null when neither base
     * CRM nor any Filament panel exposes a password-reset route.
     *
     * Base's `laravel-crm.password.reset` lives in `auth-routes.php`, which
     * `LaravelCrmServiceProvider::registerRoutes()` only loads while
     * `laravel-crm.user_interface` is on — and the plugin's own installer
     * asks operators to turn that off so the Filament panel can own `/crm`.
     * Fall back to the panel's own reset route in that (recommended) setup.
     */
    public static function setPasswordUrlFor(Model & CanResetPassword $user): ?string
    {
        if (Route::has('laravel-crm.password.reset')) {
            return route('laravel-crm.password.reset', [
                'token' => Password::createToken($user),
                'email' => $user->getEmailForPasswordReset(),
            ]);
        }

        $panel = static::panelWithPasswordReset();

        // Resolve the route before minting a token, so a host with nowhere to
        // send the invitee does not leave orphaned reset tokens behind.
        return $panel?->getResetPasswordUrl(Password::createToken($user), $user);
    }

    /**
     * Whether an invite can produce a working set-password link at all. Used
     * as a pre-flight so the invite action never creates an account nobody
     * can sign into.
     */
    public static function canBuildSetPasswordUrl(): bool
    {
        return Route::has('laravel-crm.password.reset')
            || static::panelWithPasswordReset() !== null;
    }

    /**
     * The current panel when it offers a reachable password-reset route, else
     * the first registered panel that does.
     */
    protected static function panelWithPasswordReset(): ?Panel
    {
        try {
            $current = Filament::getCurrentOrDefaultPanel();

            if ($current !== null && static::panelResetRouteExists($current)) {
                return $current;
            }

            foreach (Filament::getPanels() as $panel) {
                if (static::panelResetRouteExists($panel)) {
                    return $panel;
                }
            }
        } catch (Throwable) {
            // Filament is not booted (queue worker without a panel context).
        }

        return null;
    }

    /**
     * `hasPasswordReset()` reflects the panel's configuration; the route only
     * exists once Filament has registered that panel's routes. Require both,
     * so a link is never built against a route that cannot resolve.
     */
    protected static function panelResetRouteExists(Panel $panel): bool
    {
        return $panel->hasPasswordReset()
            && Route::has($panel->generateRouteName('auth.password-reset.reset'));
    }

    /**
     * The host's configured user model, or null when it is unusable.
     *
     * @return class-string<Model>|null
     */
    public static function userModel(): ?string
    {
        $model = config('auth.providers.users.model');

        if (! is_string($model) || ! is_a($model, Model::class, true)) {
            return null;
        }

        return $model;
    }
}
