<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Users\Pages\Concerns;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use VentureDrake\LaravelCrm\Http\Rules\AssignableRole;
use VentureDrake\LaravelCrm\Models\Role;
use VentureDrake\LaravelCrm\Models\UserInvitation;
use VentureDrake\LaravelCrmFilament\Notifications\UserInvitationNotification;
use VentureDrake\LaravelCrmFilament\Resources\Users\UserResource;
use VentureDrake\LaravelCrmFilament\Support\InvitableEmail;
use VentureDrake\LaravelCrmFilament\Support\InvitationUrl;
use VentureDrake\LaravelCrmFilament\Support\TenancyGuard;
use VentureDrake\LaravelCrmFilament\Support\UserGate;
use VentureDrake\LaravelCrmFilament\Support\UserInvitationAcceptor;

/**
 * Invite-a-user header action — the Filament equivalent of core CRM's
 * `Livewire\Users\UserInvite`.
 *
 * This used to create the host user up front with a random password and a
 * synced role, which is exactly what core 2.4.0 replaced: a mistyped address
 * left a real account nobody could sign into, burning the unique email index,
 * and the role was assigned before anyone proved they controlled the mailbox.
 * Now it mints a {@see UserInvitation} and mails a one-time code; the account
 * is created (or granted CRM access) only at redemption — see
 * {@see UserInvitationAcceptor}.
 */
trait HasUserInviteAction
{
    /**
     * Core CRM's UserPolicy::create() permission, seeded by
     * LaravelCrmTablesSeeder.
     */
    public const INVITE_PERMISSION = 'create crm users';

    protected function userInviteAction(): Action
    {
        return Action::make('invite')
            ->label(__('laravel-crm-filament::labels.actions.invite_user'))
            ->icon('heroicon-o-envelope')
            ->color('gray')
            ->modalHeading(__('laravel-crm-filament::labels.actions.invite_user'))
            ->modalSubmitActionLabel(__('laravel-crm-filament::labels.actions.send_invite'))
            // Runs core's UserPolicy rather than a raw permission string
            // through the fail-open ChecksCrmPermissions trait — see UserGate
            // for why it is not a bare Gate::allows(). ->authorize() is
            // enforced server-side on mount and on call, not merely hidden.
            // The tenancy check belongs in authorize(), not only in visible():
            // ->visible() hides a button, it does not stop a hand-rolled
            // Livewire call, and a holder of `create crm users` could otherwise
            // still mint an untenanted invitation on a teams install.
            ->authorize(fn (): bool => static::canInviteCrmUsers() && ! TenancyGuard::isEnabled())
            ->visible(fn (): bool => static::canInviteCrmUsers() && ! TenancyGuard::isEnabled())
            ->schema([
                TextInput::make('email')
                    ->label(__('laravel-crm-filament::labels.fields.email'))
                    ->email()
                    ->required()
                    ->maxLength(255)
                    // Core's UserInvite::rules() — reject an address that is
                    // already a host user, and reject a second invitation while
                    // one is still pending. No ->unique(): the invitation, not
                    // the user row, is what is being created here.
                    ->rules([new InvitableEmail]),

                Select::make('role_id')
                    ->label(__('laravel-crm-filament::labels.fields.role'))
                    ->options(fn () => Role::assignableBy()->orderBy('name')->pluck('name', 'id'))
                    ->rules([new AssignableRole])
                    ->required()
                    ->searchable()
                    ->preload(),
            ])
            ->action(function (array $data): void {
                // The Gate again, as the first statement of the closure. A
                // Livewire action is callable by anyone who can reach the page,
                // whether or not ->visible() ever rendered the button.
                abort_unless(static::canInviteCrmUsers() && ! TenancyGuard::isEnabled(), 403);

                // Pre-flight: without a reachable acceptance route the invitee
                // would receive a mail with a dead link, so refuse before the
                // invitation is committed rather than after.
                if (! InvitationUrl::exists()) {
                    Notification::make()
                        ->title(__('laravel-crm-filament::labels.notifications.user_invite_unavailable'))
                        ->body(__('laravel-crm-filament::labels.notifications.user_invite_unavailable_body'))
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                $this->inviteCrmUser($data);

                Notification::make()
                    ->title(__('laravel-crm-filament::labels.notifications.user_invited'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Mint the invitation and mail it.
     *
     * Created through the model rather than a query builder insert so the
     * UserInvitationObserver mints `external_id` and the 64-character `code`.
     * `team_id` is omitted (core's BelongsToTeams stamps it only when teams are
     * on, and the plugin is single-tenant) and so is `expires_at` — core's
     * invitations are open-ended.
     *
     * @param  array<string, mixed>  $data
     */
    protected function inviteCrmUser(array $data): UserInvitation
    {
        $email = strtolower(trim((string) ($data['email'] ?? '')));

        return DB::transaction(function () use ($data, $email): UserInvitation {
            $invitation = UserInvitation::create([
                'email' => $email,
                'role_id' => $data['role_id'] ?? null,
                'invited_by' => auth()->id(),
                'last_sent_at' => now(),
            ]);

            NotificationFacade::route('mail', $email)
                ->notify(new UserInvitationNotification($invitation));

            return $invitation;
        });
    }

    protected static function canInviteCrmUsers(): bool
    {
        return UserGate::allows('create', self::INVITE_PERMISSION);
    }

    /**
     * The host's configured user model — never a hardcoded App\Models\User.
     *
     * @return class-string<Model>
     */
    protected static function inviteUserModel(): string
    {
        /** @var class-string<Model> $model */
        $model = UserResource::getModel();

        return $model;
    }

    protected static function inviteUserTable(): string
    {
        $model = static::inviteUserModel();

        return (new $model)->getTable();
    }
}
