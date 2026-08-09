<?php

namespace VentureDrake\LaravelCrmFilament\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use VentureDrake\LaravelCrm\Models\Role;
use VentureDrake\LaravelCrm\Models\UserInvitation;
use VentureDrake\LaravelCrmFilament\Support\InvitationUrl;

/**
 * The invitation mail, resolved through {@see InvitationUrl}.
 *
 * Core's own UserInvitationNotification builds the accept URL with a bare
 * `route('laravel-crm.users.invitations.accept', …)`. On a panel-only install
 * that route does not exist, so the call throws RouteNotFoundException from
 * inside a queued job — after the admin has already been told the invitation
 * was sent. This one resolves the URL in {@see self::via()} and withdraws the
 * mail channel when there isn't one, the same shape as
 * SendUserInvite::handle()'s bail.
 *
 * Otherwise identical to core's, minus the team-name line: the plugin is
 * single-tenant by design (see TenancyGuard), so invitations carry no team.
 *
 * Upstream fix (recommended): have core's notification resolve the URL and
 * bail rather than throw, so a misconfigured host degrades to a logged
 * warning instead of a failed job.
 */
class UserInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Resolved in via() and carried to the worker, so the URL is built where
     * Filament is booted rather than in a queue process that has no panel.
     */
    protected ?string $acceptUrl = null;

    public function __construct(protected UserInvitation $invitation) {}

    /**
     * The bail lives here rather than in toMail(), and the difference matters.
     *
     * MailChannel::send() only tolerates a null message when the notifiable's
     * mail route is *also* falsy. Every send of this notification goes through
     * `Notification::route('mail', $email)`, whose AnonymousNotifiable returns
     * a truthy route, so returning null from toMail() sailed past that guard
     * and fataled on `$message->view` — trading core's RouteNotFoundException
     * for a different crash rather than degrading. Returning no channels at all
     * is the only thing that actually stops the send.
     *
     * @return array<int, string>
     */
    public function via($notifiable): array
    {
        $this->acceptUrl = InvitationUrl::acceptUrl($this->invitation);

        if ($this->acceptUrl === null) {
            Log::warning('[laravel-crm-filament] Skipped the invitation mail: no accept-invitation route is registered. Register the CRM panel plugin (which owns the route) or set laravel-crm.user_interface=true.', [
                'invitation_id' => $this->invitation->id,
                'email' => $this->invitation->email,
            ]);

            return [];
        }

        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        // Non-null by construction: via() returned no channels otherwise. The
        // re-resolve covers a caller that reaches toMail() directly.
        $url = $this->acceptUrl ??= InvitationUrl::acceptUrl($this->invitation);

        $appName = config('app.name');

        $message = (new MailMessage)
            ->subject(trans('laravel-crm::lang.user_invitation_subject', ['app' => $appName]))
            ->greeting(trans('laravel-crm::lang.user_invitation_greeting'))
            ->line(trans('laravel-crm::lang.user_invitation_intro', [
                'inviter' => $this->resolveInviterName(),
                'app' => $appName,
            ]));

        if ($roleName = $this->resolveRoleName()) {
            $message->line(trans('laravel-crm::lang.user_invitation_role_line', ['role' => $roleName]));
        }

        if ($this->invitation->expires_at !== null) {
            $message->line(trans('laravel-crm::lang.user_invitation_expires_line', [
                'date' => $this->invitation->expires_at->toDayDateTimeString(),
            ]));
        } else {
            $message->line(trans('laravel-crm::lang.user_invitation_no_expiry_line'));
        }

        return $message
            ->action(trans('laravel-crm::lang.user_invitation_action'), $url)
            ->line(trans('laravel-crm::lang.user_invitation_outro'));
    }

    protected function resolveInviterName(): string
    {
        $inviterId = $this->invitation->invited_by ?? null;

        if ($inviterId === null) {
            return config('app.name');
        }

        $userModel = config('auth.providers.users.model');

        if (! is_string($userModel) || ! class_exists($userModel)) {
            return config('app.name');
        }

        $inviter = $userModel::find($inviterId);

        return $inviter?->getAttribute('name') ?? config('app.name');
    }

    protected function resolveRoleName(): ?string
    {
        $roleId = $this->invitation->role_id ?? null;

        return $roleId === null
            ? null
            : Role::query()->whereKey($roleId)->value('name');
    }
}
