<?php

namespace VentureDrake\LaravelCrmFilament\Pages;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Pages\SimplePage;
use Filament\Panel;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportRedirects\Redirector;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use VentureDrake\LaravelCrm\Models\UserInvitation;
use VentureDrake\LaravelCrmFilament\Support\InvitationUrl;
use VentureDrake\LaravelCrmFilament\Support\UserInvitationAcceptor;

/**
 * The panel's own accept-invitation screen.
 *
 * Core's equivalent is unreachable on a panel-only install — see
 * {@see InvitationUrl} for why — so
 * the plugin owns this surface, exactly as it already owns the portal-link
 * guard and the invite mail job.
 *
 * Registered through `$panel->routes(...)`, which Filament groups *outside*
 * the panel's auth middleware: the invitee is by definition not a panel user
 * yet. All the branching lives in {@see UserInvitationAcceptor} so the GET and
 * the POST cannot diverge and it can be tested without a browser.
 *
 * @property-read Schema $form
 */
class AcceptInvitation extends SimplePage
{
    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public string $code = '';

    public ?UserInvitation $invitation = null;

    /**
     * True when the invitee has no host account yet and must supply a name
     * and a password. The other branches all redirect or abort in mount().
     */
    public bool $needsRegistration = false;

    /**
     * Path relative to the panel, i.e. `/crm/invitations/accept/{code}`.
     */
    public const ROUTE_PATH = '/invitations/accept/{code}';

    public const RELATIVE_ROUTE_NAME = 'invitations.accept';

    /**
     * Register the acceptance route on $panel.
     *
     * Called from LaravelCrmPlugin::register() via `$panel->routes(...)`, so
     * it lands in the group before `Route::middleware($panel->getAuthMiddleware())`
     * and stays reachable to a logged-out invitee.
     */
    public static function registerPanelRoute(Panel $panel): void
    {
        Route::get(self::ROUTE_PATH, static::class)->name(self::RELATIVE_ROUTE_NAME);
    }

    /**
     * The fully-qualified route name on $panel — Filament prefixes panel
     * routes with `filament.{panelId}.`, so this cannot be a constant.
     */
    public static function routeNameFor(Panel $panel): string
    {
        return $panel->generateRouteName(self::RELATIVE_ROUTE_NAME);
    }

    public function mount(string $code): void
    {
        $this->code = $code;

        $invitation = UserInvitationAcceptor::find($code);
        $result = UserInvitationAcceptor::inspect($invitation);

        switch ($result['outcome']) {
            case UserInvitationAcceptor::OUTCOME_INVALID:
                throw new NotFoundHttpException(
                    trans('laravel-crm::lang.user_invitation_invalid_message')
                );

            case UserInvitationAcceptor::OUTCOME_LOGIN_REQUIRED:
                // An existing host user who is signed out: send them to the
                // panel login carrying ?invitation= so the link still works
                // after they authenticate.
                redirect()->to($result['url']);

                return;

            case UserInvitationAcceptor::OUTCOME_WRONG_USER:
                throw new AccessDeniedHttpException(
                    trans('laravel-crm::lang.user_invitation_wrong_user', ['email' => $invitation->email])
                );

            case UserInvitationAcceptor::OUTCOME_ACCEPTED:
                UserInvitationAcceptor::acceptExistingUser($invitation, $result['user']);

                redirect()->intended(Filament::getUrl());

                return;
        }

        $this->invitation = $invitation;
        $this->needsRegistration = true;

        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                TextInput::make('name')
                    ->label(__('laravel-crm-filament::labels.fields.name'))
                    ->required()
                    ->maxLength(255)
                    ->autofocus(),

                TextInput::make('password')
                    ->label(__('laravel-crm-filament::labels.invitations.password'))
                    ->password()
                    ->revealable(Filament::arePasswordsRevealable())
                    ->required()
                    ->minLength(8)
                    // Filament has to own the match check, not just the
                    // validator inside acceptNewUser(): that one keys its error
                    // on `password` while this field's state path is
                    // `data.password`, so a mismatch bound to nothing and the
                    // invitee saw the button do precisely nothing.
                    ->same('password_confirmation'),

                TextInput::make('password_confirmation')
                    ->label(__('laravel-crm-filament::labels.invitations.password_confirmation'))
                    ->password()
                    ->revealable(Filament::arePasswordsRevealable())
                    ->required()
                    ->dehydrated(),
            ]);
    }

    /**
     * Returns void, and redirects through Livewire's own helpers, deliberately.
     *
     * Inside a Livewire component `app('redirect')` is rebound to
     * {@see Redirector}, whose `to()` (and
     * therefore the inherited `intended()`) returns `$this` rather than an
     * `Illuminate\Http\RedirectResponse`. Declaring a `?RedirectResponse`
     * return type here threw a TypeError that Livewire turns into a bare HTTP
     * 419 — after the user had already been created and logged in — so a
     * successful acceptance looked like "page expired" and the reloaded link
     * then 404'd on the now-stamped invitation.
     */
    public function accept(): void
    {
        // Re-resolved rather than trusted from the public property: every
        // public Livewire property is client-writable, and $this->invitation
        // came back through the browser between mount() and this call.
        $invitation = UserInvitationAcceptor::find($this->code);

        if ($invitation === null) {
            throw new NotFoundHttpException(
                trans('laravel-crm::lang.user_invitation_invalid_message')
            );
        }

        $result = UserInvitationAcceptor::inspect($invitation);

        if ($result['outcome'] !== UserInvitationAcceptor::OUTCOME_NEEDS_REGISTRATION) {
            // The account was created in another tab (or the invitee signed in)
            // between rendering this form and submitting it. Restart the flow
            // rather than trying to create a duplicate user.
            $this->redirect(route(
                self::routeNameFor(Filament::getCurrentOrDefaultPanel()),
                ['code' => $this->code]
            ));

            return;
        }

        UserInvitationAcceptor::acceptNewUser($invitation, $this->form->getState());

        session()->regenerate();

        $this->redirectIntended(Filament::getUrl());
    }

    public function getTitle(): string | Htmlable
    {
        return __('laravel-crm-filament::labels.invitations.accept_title');
    }

    public function getHeading(): string | Htmlable | null
    {
        return __('laravel-crm-filament::labels.invitations.accept_heading');
    }

    public function getSubheading(): string | Htmlable | null
    {
        return __('laravel-crm-filament::labels.invitations.accept_subheading');
    }

    /**
     * Composed the same way Filament's own Register page composes its form, so
     * the page renders through `filament-panels::pages.simple` with no custom
     * Blade of its own.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('accept')
                ->footer([
                    Actions::make($this->getFormActions())
                        ->fullWidth($this->hasFullWidthFormActions())
                        ->key('form-actions'),
                ]),
        ]);
    }

    public function acceptFormAction(): Action
    {
        return Action::make('accept')
            ->label(__('laravel-crm-filament::labels.invitations.accept_action'))
            ->submit('accept');
    }

    /**
     * @return array<Action>
     */
    public function getFormActions(): array
    {
        return [$this->acceptFormAction()];
    }

    public function hasFullWidthFormActions(): bool
    {
        return true;
    }
}
