<?php

namespace VentureDrake\LaravelCrmFilament\Concerns;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrm\Support\PortalLink;
use VentureDrake\LaravelCrmFilament\Support\PortalUrl;

/**
 * The "Get link" action — core 2.4.1's modal, as a Filament modal.
 *
 * The signed portal URL a customer receives had exactly one way out of the CRM
 * before this: send the document. Wanting the link without sending the email —
 * to paste into a reply, a chat, a ticket — meant sending the email.
 *
 * It is the *same* link, not a second one: minted through
 * {@see PortalUrl::signedFor()}, so it carries the same signature and the same
 * {@see PortalLink::DAYS} expiry as the one the mailer embeds. A "copy link"
 * that quietly issued a longer-lived or differently-scoped URL would be a
 * second credential wearing the first one's name.
 *
 * Read-only and copyable rather than a bare label: the URL is long, signed, and
 * unusable if a hand-selection clips a character off the signature.
 *
 * Only the three doc types with a portal page get one. Orders and deliveries
 * have PDF previews but no portal route (see {@see PortalUrl}), so there is
 * nothing to hand over.
 */
trait HasPortalLinkAction
{
    /**
     * Build the Get-link action for a portal route.
     */
    public static function portalLinkAction(string $routeName): Action
    {
        return Action::make('getPortalLink')
            ->label(__('laravel-crm-filament::labels.actions.get_link'))
            ->icon('heroicon-o-link')
            ->color('gray')
            ->modalHeading(__('laravel-crm-filament::labels.actions.get_link_heading'))
            ->modalDescription(__('laravel-crm-filament::labels.actions.get_link_description', [
                'days' => PortalLink::DAYS,
            ]))
            ->modalSubmitAction(false)
            ->modalCancelAction(false)
            ->schema(fn (Model $record): array => [
                TextInput::make('portal_link')
                    ->label(__('laravel-crm-filament::labels.actions.get_link'))
                    ->default(PortalUrl::signedFor($routeName, $record))
                    ->readOnly()
                    ->copyable()
                    ->extraInputAttributes(['onclick' => 'this.select()']),
            ])
            // Hidden, not dead, when core does not register the route — which
            // is what `laravel-crm.user_interface=false` produces, and that is
            // the configuration this plugin's installer recommends.
            ->visible(fn (): bool => PortalUrl::exists($routeName));
    }
}
