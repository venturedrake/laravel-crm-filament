<?php

namespace VentureDrake\LaravelCrmFilament\Support;

use VentureDrake\LaravelCrm\Models\PurchaseOrder;

/**
 * The "[Online Purchase Order Link]" placeholder base's `SendPurchaseOrder`
 * mailable expands, and the route it needs.
 *
 * base does not register a purchase-order portal route (only quotes and
 * invoices — see vendor/venturedrake/laravel-crm/src/Http/routes.php), and the
 * mailable expands the placeholder unconditionally:
 *
 *     str_replace($placeholder, '<a href="'.$link.'">'.$link.'</a>', $content)
 *
 * so handing it an empty link mails `<a href=""></a>` — an anchor with no href
 * and no visible text. Both send paths therefore seed the placeholder only when
 * the route resolves, and strip it from a hand-written message otherwise.
 */
class PurchaseOrderPortalLink
{
    public const ROUTE = 'laravel-crm.portal.purchase-orders.show';

    public const PLACEHOLDER = '[Online Purchase Order Link]';

    /**
     * Days a generated portal link stays valid — base's own send flow uses 14.
     */
    public const EXPIRY_DAYS = 14;

    public static function available(): bool
    {
        return PortalUrl::exists(self::ROUTE);
    }

    /**
     * A temporary signed portal link, or null when the route is unavailable.
     */
    public static function signedFor(PurchaseOrder $record): ?string
    {
        return PortalUrl::temporarySignedFor(
            self::ROUTE,
            $record,
            now()->addDays(self::EXPIRY_DAYS),
        );
    }

    /**
     * The pre-filled send-modal body: it only references the portal link when
     * there is a portal to link to. The PDF is attached either way.
     */
    public static function defaultMessage(): string
    {
        if (! self::available()) {
            return "Hi,\n\nPlease find the purchase order attached.\n\nThanks.";
        }

        return "Hi,\n\nPlease find the purchase order here: " . self::PLACEHOLDER . "\n\nThanks.";
    }

    /**
     * Remove any line carrying the placeholder. Dropping the whole line rather
     * than just the token avoids mailing a dangling "Please find it here:".
     */
    public static function stripPlaceholder(string $message): string
    {
        if (! str_contains($message, self::PLACEHOLDER)) {
            return $message;
        }

        $stripped = preg_replace(
            '/^.*' . preg_quote(self::PLACEHOLDER, '/') . '.*$\R?/m',
            '',
            $message,
        );

        return $stripped ?? $message;
    }
}
