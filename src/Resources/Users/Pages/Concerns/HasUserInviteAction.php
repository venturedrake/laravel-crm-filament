<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Users\Pages\Concerns;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use VentureDrake\LaravelCrmFilament\Concerns\ChecksCrmPermissions;
use VentureDrake\LaravelCrmFilament\Jobs\SendUserInvite;
use VentureDrake\LaravelCrmFilament\Resources\Users\UserResource;

/**
 * Invite-a-user header action — the Filament equivalent of core CRM's
 * `laravel-crm.users.invite` / `laravel-crm.users.sendinvite` pair, so an
 * administrator can onboard someone without leaving the panel.
 *
 * Core's `UserController@sendInvite` only flashes a message; here the invite
 * actually creates the user (random password, no password ever shown or
 * emailed), assigns the chosen Spatie role, and dispatches the plugin's
 * `SendUserInvite` job — which mails core's `WelcomeImportedUser` carrying a
 * password-reset link the invitee uses to set their own password.
 */
trait HasUserInviteAction
{
    use ChecksCrmPermissions;

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
            ->visible(fn (): bool => static::canInviteCrmUsers())
            ->schema([
                TextInput::make('name')
                    ->label(__('laravel-crm-filament::labels.fields.name'))
                    ->required()
                    ->maxLength(255),

                TextInput::make('email')
                    ->label(__('laravel-crm-filament::labels.fields.email'))
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(table: static::inviteUserTable(), column: 'email'),

                Select::make('role_id')
                    ->label(__('laravel-crm-filament::labels.fields.role'))
                    ->options(fn () => Role::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->preload(),

                Toggle::make('crm_access')
                    ->label(__('laravel-crm-filament::labels.misc.crm_access'))
                    ->helperText(__('laravel-crm-filament::labels.misc.crm_access_helper'))
                    ->default(true),
            ])
            ->action(function (array $data): void {
                // Pre-flight: without a reachable password-reset route the
                // invitee could never set a password, so refuse before an
                // unusable account is committed rather than after.
                if (! SendUserInvite::canBuildSetPasswordUrl()) {
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
     * Create the invited user and queue their welcome / set-password mail.
     *
     * @param  array<string, mixed>  $data
     */
    protected function inviteCrmUser(array $data): Model
    {
        $model = static::inviteUserModel();

        $user = $model::forceCreate([
            'name' => trim((string) ($data['name'] ?? '')),
            'email' => strtolower(trim((string) ($data['email'] ?? ''))),
            // Never surfaced to anyone — the invitee sets their own password
            // through the reset link in the invite mail.
            'password' => Hash::make(Str::password(
                length: 32,
                letters: true,
                numbers: true,
                symbols: true,
                spaces: false,
            )),
            'crm_access' => empty($data['crm_access']) ? 0 : 1,
        ]);

        if (filled($data['role_id'] ?? null) && method_exists($user, 'syncRoles')) {
            $role = Role::query()->find($data['role_id']);

            if ($role) {
                $user->syncRoles([$role]);
            }
        }

        SendUserInvite::dispatch((string) $user->getAttribute('email'));

        return $user;
    }

    protected static function canInviteCrmUsers(): bool
    {
        return static::userHasCrmPermission(self::INVITE_PERMISSION);
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
