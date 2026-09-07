<?php

namespace VentureDrake\LaravelCrmFilament\Support;

use InvalidArgumentException;

/**
 * The "[Online … Link]" placeholder base's quote and invoice send mailables
 * expand, and the message body that carries it.
 *
 * `SendQuote` and `SendInvoice` both expand their placeholder unconditionally:
 *
 *     str_replace($placeholder, '<a href="'.$link.'">'.$link.'</a>', $content)
 *
 * so handing either an empty link mails `<a href=""></a>` — an anchor with no
 * href and no visible text — sitting under a line that says "please find it
 * here:". The link is empty exactly when base does not register the portal
 * route, which `laravel-crm.user_interface=false` guarantees (it unloads
 * `portal-routes.php` with the rest of the web UI) and which is the
 * configuration this plugin's own installer recommends. Until the send paths
 * moved onto {@see PortalUrl::signedFor()} that case threw
 * `RouteNotFoundException` out of `URL::temporarySignedRoute()` instead — loud,
 * but never a mailed dead link.
 *
 * Both halves are load-bearing:
 *
 *  - {@see self::defaultMessage()} only offers the placeholder when there is a
 *    portal to link to, so the common path never sees it.
 *  - {@see self::resolve()} strips it at send time, which covers the body a
 *    user edited, pasted or typed the placeholder into by hand.
 *
 * Purchase orders got this treatment first — see {@see PurchaseOrderPortalLink},
 * which keeps its own doc-type-specific API and shares the stripping rule from
 * here so there is one copy of it.
 */
class PortalMessage
{
    public const QUOTE = 'quote';

    public const INVOICE = 'invoice';

    /**
     * Doc type => its portal route, placeholder, and the two body variants.
     *
     * The "no link" variant says "attached" rather than dropping the sentence:
     * the PDF is attached either way, so a recipient with no portal still gets
     * told where the document is.
     *
     * @var array<string, array{route: string, placeholder: string, with_link: string, without_link: string}>
     */
    private const DOCUMENTS = [
        self::QUOTE => [
            'route' => 'laravel-crm.portal.quotes.show',
            'placeholder' => '[Online Quote Link]',
            'with_link' => "Hi,\n\nPlease find your quote here: [Online Quote Link]\n\nThanks.",
            'without_link' => "Hi,\n\nPlease find your quote attached.\n\nThanks.",
        ],
        self::INVOICE => [
            'route' => 'laravel-crm.portal.invoices.show',
            'placeholder' => '[Online Invoice Link]',
            'with_link' => "Hi,\n\nPlease find your invoice here: [Online Invoice Link]\n\nThanks.",
            'without_link' => "Hi,\n\nPlease find your invoice attached.\n\nThanks.",
        ],
    ];

    public static function route(string $type): string
    {
        return self::document($type)['route'];
    }

    public static function placeholder(string $type): string
    {
        return self::document($type)['placeholder'];
    }

    /**
     * Whether base registers this document's portal route.
     */
    public static function available(string $type): bool
    {
        return PortalUrl::exists(self::route($type));
    }

    /**
     * The pre-filled send-modal body: it only references the portal link when
     * there is a portal to link to.
     */
    public static function defaultMessage(string $type): string
    {
        $document = self::document($type);

        return self::available($type)
            ? $document['with_link']
            : $document['without_link'];
    }

    /**
     * The body to actually mail, given the link that resolved for it. A null
     * link means the placeholder has nothing to expand to, so it goes.
     */
    public static function resolve(string $type, string $message, ?string $link): string
    {
        if ($link !== null) {
            return $message;
        }

        return self::stripPlaceholder($message, self::placeholder($type));
    }

    /**
     * Remove any line carrying the placeholder. Dropping the whole line rather
     * than just the token avoids mailing a dangling "Please find it here:".
     */
    public static function stripPlaceholder(string $message, string $placeholder): string
    {
        if (! str_contains($message, $placeholder)) {
            return $message;
        }

        $stripped = preg_replace(
            '/^.*' . preg_quote($placeholder, '/') . '.*$\R?/m',
            '',
            $message,
        );

        return $stripped ?? $message;
    }

    /**
     * @return array{route: string, placeholder: string, with_link: string, without_link: string}
     */
    private static function document(string $type): array
    {
        if (! array_key_exists($type, self::DOCUMENTS)) {
            throw new InvalidArgumentException("No portal message is defined for '{$type}'.");
        }

        return self::DOCUMENTS[$type];
    }
}
