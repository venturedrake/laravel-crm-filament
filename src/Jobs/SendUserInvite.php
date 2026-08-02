<?php

namespace VentureDrake\LaravelCrmFilament\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use VentureDrake\LaravelCrm\Mail\WelcomeImportedUser;

/**
 * Host-agnostic wrapper around core CRM's `SendImportPasswordReset`.
 *
 * Core's job opens with `use App\Models\User;` and queries that class
 * directly, so it silently sends nothing on any host whose user model lives
 * somewhere else (a `Domain\Users\User`, an `App\User` on a Laravel 7-era
 * app, a package test stub, …). This job is otherwise identical — same
 * password-reset token, same `laravel-crm.password.reset` URL, same
 * `Mail\WelcomeImportedUser` mailable — but resolves the model the host
 * actually authenticates with, `config('auth.providers.users.model')`.
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

        $setPasswordUrl = route('laravel-crm.password.reset', [
            'token' => Password::createToken($user),
            'email' => $user->getEmailForPasswordReset(),
        ]);

        Mail::send(new WelcomeImportedUser(
            name: (string) $user->getAttribute('name'),
            recipientEmail: (string) $user->getAttribute('email'),
            setPasswordUrl: $setPasswordUrl,
        ));
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
