<?php

namespace VentureDrake\LaravelCrmFilament\Resources\UserInvitations;

use BackedEnum;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use VentureDrake\LaravelCrm\Models\UserInvitation;
use VentureDrake\LaravelCrmFilament\Notifications\UserInvitationNotification;
use VentureDrake\LaravelCrmFilament\Resources\UserInvitations\Pages\ListUserInvitations;
use VentureDrake\LaravelCrmFilament\Resources\Users\UserResource;
use VentureDrake\LaravelCrmFilament\Support\InvitationUrl;
use VentureDrake\LaravelCrmFilament\Support\UserGate;

/**
 * The pending-invitations list — an index page and nothing else.
 *
 * `UserInvitation::getRouteKeyName()` is `code`, the 64-character secret the
 * invitee redeems with. Registering a View or an Edit page would route-bind
 * the record, putting that secret in a URL, and from there into browser
 * history, referrer headers and every access log between here and the server.
 * There is no View/Edit page, so there is no URL: that is the reason this
 * resource is index-only, and it is why "let's add a view page for
 * completeness" is the wrong instinct.
 *
 * Tabs on ListUsers cannot serve this — getTabs() filters the same model
 * query — and a second ListRecords page on UserResource would mean overriding
 * both table() and getEloquentQuery() for no gain. So: its own resource,
 * hidden from navigation, reached by a badged header action on ListUsers.
 */
class UserInvitationResource extends Resource
{
    protected static ?string $model = UserInvitation::class;

    protected static ?string $slug = 'user-invitations';

    protected static ?string $recordTitleAttribute = 'email';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-envelope';

    /**
     * Reached from ListUsers, not from the sidebar — a pending-invitations
     * list is a sub-view of Users, not a peer of it.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getNavigationLabel(): string
    {
        return __('laravel-crm-filament::labels.invitations.pending');
    }

    /**
     * Mirrors core's UserIndex::pendingInvitationsQuery(): not accepted, not
     * expired. Soft-deleted (revoked) rows drop out via the model's
     * SoftDeletes.
     */
    public static function getEloquentQuery(): Builder
    {
        return UserInvitation::query()
            ->whereNull('accepted_at')
            ->where(function (Builder $query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('email')
                    ->label(__('laravel-crm-filament::labels.fields.email'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('role.name')
                    ->label(__('laravel-crm-filament::labels.fields.role'))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('invitedByUser.name')
                    ->label(__('laravel-crm-filament::labels.invitations.invited_by'))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('laravel-crm-filament::labels.invitations.sent_at'))
                    ->since()
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_sent_at')
                    ->label(__('laravel-crm-filament::labels.invitations.last_sent'))
                    ->since()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label(__('laravel-crm-filament::labels.invitations.expires'))
                    ->dateTime()
                    ->placeholder(__('laravel-crm-filament::labels.invitations.never_expires')),
            ])
            ->defaultSort('last_sent_at', 'desc')
            ->paginationPageOptions([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->recordActions([
                static::resendAction(),
                Actions\DeleteAction::make()
                    ->label(__('laravel-crm-filament::labels.invitations.revoke'))
                    ->button()
                    ->requiresConfirmation()
                    ->modalHeading(__('laravel-crm-filament::labels.invitations.revoke'))
                    ->modalDescription(__('laravel-crm-filament::labels.invitations.revoke_confirm'))
                    ->authorize(fn (): bool => static::canManageInvitations())
                    ->before(fn () => abort_unless(static::canManageInvitations(), 403)),
            ]);
    }

    /**
     * Re-send the invitation mail and stamp last_sent_at.
     *
     * Deliberately does NOT mint a new code — matching core, and so a user who
     * kept the first mail is not locked out by an admin clicking "resend".
     */
    public static function resendAction(): Action
    {
        return Action::make('resend')
            ->label(__('laravel-crm-filament::labels.invitations.resend'))
            ->icon('heroicon-o-paper-airplane')
            ->color('gray')
            ->button()
            ->authorize(fn (): bool => static::canManageInvitations())
            ->action(function (UserInvitation $record): void {
                abort_unless(static::canManageInvitations(), 403);

                // The same pre-flight the invite action runs. Without it the
                // notification withdraws its own mail channel and the admin is
                // still shown a green "resent" toast for mail nobody sent —
                // and last_sent_at would be stamped to say so.
                if (! InvitationUrl::exists()) {
                    Notification::make()
                        ->title(__('laravel-crm-filament::labels.notifications.user_invite_unavailable'))
                        ->body(__('laravel-crm-filament::labels.notifications.user_invite_unavailable_body'))
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                NotificationFacade::route('mail', $record->email)
                    ->notify(new UserInvitationNotification($record));

                $record->forceFill(['last_sent_at' => now()])->save();

                Notification::make()
                    ->title(__('laravel-crm-filament::labels.invitations.resent'))
                    ->success()
                    ->send();
            });
    }

    public static function backToIndexAction(): Action
    {
        return Action::make('backToInvitations')
            ->label(__('laravel-crm-filament::labels.invitations.pending'))
            ->icon('heroicon-o-envelope')
            ->color('gray')
            ->badge(fn (): ?string => static::pendingBadge())
            ->visible(fn (): bool => static::canManageInvitations())
            ->url(static::getUrl('index'));
    }

    public static function pendingBadge(): ?string
    {
        $count = static::getEloquentQuery()->count();

        return $count > 0 ? (string) $count : null;
    }

    /**
     * Core ships no UserInvitationPolicy, and Filament allows when no policy
     * resolves — which would have made this list, its resend and its revoke
     * open to every panel user. Delegate to UserPolicy against the host user
     * model, which is what core's own Livewire component does
     * (`$this->authorize('create', User::class)`).
     */
    protected static function canManageInvitations(): bool
    {
        return UserGate::canCreateUsers();
    }

    public static function canViewAny(): bool
    {
        return static::canManageInvitations();
    }

    public static function canCreate(): bool
    {
        // Invitations are minted by the invite action on ListUsers, not here.
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return static::canManageInvitations();
    }

    public static function canDeleteAny(): bool
    {
        return static::canManageInvitations();
    }

    public static function getPages(): array
    {
        // index only — see the class docblock. Do not add view/edit.
        return [
            'index' => ListUserInvitations::route('/'),
        ];
    }
}
