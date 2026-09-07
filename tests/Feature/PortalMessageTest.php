<?php

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Mail\SendInvoice;
use VentureDrake\LaravelCrm\Mail\SendQuote;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrmFilament\Support\PortalMessage;
use VentureDrake\LaravelCrmFilament\Support\PortalUrl;

/**
 * Base's `SendQuote` and `SendInvoice` expand their `[Online … Link]`
 * placeholder unconditionally, so handing either an empty link mails
 * `<a href=""></a>` — an anchor with neither an href nor visible text — under a
 * line that says "please find it here:". The link is empty exactly when base
 * does not register the portal route, which `laravel-crm.user_interface=false`
 * guarantees and which the plugin's own installer recommends.
 *
 * Before the send paths moved onto `PortalUrl::signedFor()` that case threw
 * `RouteNotFoundException` out of `URL::temporarySignedRoute()` — loud, but
 * never a mailed dead link. These lock in that the placeholder is only ever
 * offered, and only ever expanded, when the route actually resolves.
 *
 * The purchase-order half of the same rule lives in PurchaseOrderPortalLinkTest.
 */
function withoutPortalRoute(string $routeName): void
{
    $remaining = new RouteCollection;

    foreach (Route::getRoutes() as $route) {
        if ($route->getName() === $routeName) {
            continue;
        }

        $remaining->add($route);
    }

    app('router')->setRoutes($remaining);
}

it('resolves the route and placeholder core actually uses', function (string $type, string $route, string $placeholder) {
    expect(PortalMessage::route($type))->toBe($route)
        ->and(PortalMessage::placeholder($type))->toBe($placeholder)
        // The route base registers, so `available()` is not asserting a typo.
        ->and(PortalUrl::exists($route))->toBeTrue();
})->with([
    'quote' => [PortalMessage::QUOTE, 'laravel-crm.portal.quotes.show', '[Online Quote Link]'],
    'invoice' => [PortalMessage::INVOICE, 'laravel-crm.portal.invoices.show', '[Online Invoice Link]'],
]);

it('throws rather than silently mailing a body with no placeholder rule', function () {
    PortalMessage::defaultMessage('purchaseorder');
})->throws(InvalidArgumentException::class);

it('offers the placeholder in the default message while the route exists', function (string $type) {
    expect(PortalMessage::available($type))->toBeTrue()
        ->and(PortalMessage::defaultMessage($type))->toContain(PortalMessage::placeholder($type));
})->with([PortalMessage::QUOTE, PortalMessage::INVOICE]);

it('omits the placeholder from the default message when there is no portal route', function (string $type) {
    withoutPortalRoute(PortalMessage::route($type));

    $message = PortalMessage::defaultMessage($type);

    expect(PortalMessage::available($type))->toBeFalse()
        ->and($message)->not->toContain(PortalMessage::placeholder($type))
        // The PDF is attached either way, so the recipient is still told where
        // the document is rather than the sentence just disappearing.
        ->and($message)->toContain('attached');
})->with([PortalMessage::QUOTE, PortalMessage::INVOICE]);

it('leaves the body alone when a link resolved', function (string $type) {
    $body = "Hi,\n\nHere: " . PortalMessage::placeholder($type) . "\n\nThanks.";

    expect(PortalMessage::resolve($type, $body, 'https://example.test/p/x?signature=abc'))->toBe($body);
})->with([PortalMessage::QUOTE, PortalMessage::INVOICE]);

it('drops the whole line a hand-typed placeholder sits on when no link resolved', function (string $type) {
    $stripped = PortalMessage::resolve(
        $type,
        "Hi,\n\nPlease find it here: " . PortalMessage::placeholder($type) . "\n\nThanks.",
        null,
    );

    expect($stripped)->not->toContain(PortalMessage::placeholder($type))
        // The dangling "here:" goes with it, rather than being mailed alone.
        ->not->toContain('find it here:')
        ->toContain('Hi,')
        ->toContain('Thanks.');
})->with([PortalMessage::QUOTE, PortalMessage::INVOICE]);

it('leaves a message without the placeholder untouched', function (string $type) {
    $message = "Hi,\n\nThe document is attached.\n\nThanks.";

    expect(PortalMessage::resolve($type, $message, null))->toBe($message);
})->with([PortalMessage::QUOTE, PortalMessage::INVOICE]);

/**
 * `build()` needs an authenticated user for its `from()`, so the assertion is
 * against the one line of it that matters, applied to exactly what the send
 * path hands the mailable:
 *
 *     str_replace($placeholder, '<a href="'.$link.'">'.$link.'</a>', $content)
 *
 * Feed that the unresolved body and it produces `<a href=""></a>`; that is the
 * regression, so the expansion is run here rather than assumed away.
 */
function expandPortalPlaceholder(string $content, string $placeholder, ?string $link): string
{
    return str_replace($placeholder, '<a href="' . $link . '">' . $link . '</a>', $content);
}

it('never mails an empty anchor for the quote portal link', function () {
    withoutPortalRoute(PortalMessage::route(PortalMessage::QUOTE));

    $link = PortalUrl::signedFor(PortalMessage::route(PortalMessage::QUOTE), new Quote(['external_id' => (string) Str::uuid()]));
    $placeholder = PortalMessage::placeholder(PortalMessage::QUOTE);

    expect($link)->toBeNull();

    $mail = new SendQuote([
        'to' => 'buyer@example.com',
        'subject' => 'Quote 1',
        'message' => PortalMessage::resolve(
            PortalMessage::QUOTE,
            "Hi,\n\nPlease find your quote here: " . $placeholder . "\n\nThanks.",
            $link,
        ),
        'cc' => 0,
        'onlineQuoteLink' => $link ?? '',
        'pdf' => null,
    ]);

    expect(expandPortalPlaceholder((string) $mail->content, $placeholder, $mail->onlineQuoteLink))
        ->not->toContain('<a href=""></a>')
        ->not->toContain($placeholder)
        ->toContain('Thanks.');
});

it('never mails an empty anchor for the invoice portal link', function () {
    withoutPortalRoute(PortalMessage::route(PortalMessage::INVOICE));

    $link = PortalUrl::signedFor(PortalMessage::route(PortalMessage::INVOICE), new Invoice(['external_id' => (string) Str::uuid()]));
    $placeholder = PortalMessage::placeholder(PortalMessage::INVOICE);

    expect($link)->toBeNull();

    $mail = new SendInvoice([
        'to' => 'buyer@example.com',
        'subject' => 'Invoice 1',
        'message' => PortalMessage::resolve(
            PortalMessage::INVOICE,
            "Hi,\n\nPlease find your invoice here: " . $placeholder . "\n\nThanks.",
            $link,
        ),
        'cc' => 0,
        'onlineInvoiceLink' => $link ?? '',
        'pdf' => null,
    ]);

    expect(expandPortalPlaceholder((string) $mail->content, $placeholder, $mail->onlineInvoiceLink))
        ->not->toContain('<a href=""></a>')
        ->not->toContain($placeholder)
        ->toContain('Thanks.');
});

/**
 * Four send paths, two per document: the ViewRecord page trait and the static
 * resource factory. A guard on one and not the other is the bug all over again.
 */
it('gates every quote and invoice send path on the shared helper', function (string $file, string $placeholder) {
    $source = (string) file_get_contents($file);

    expect($source)->toContain('PortalMessage::resolve(')
        ->toContain('PortalMessage::defaultMessage(')
        // Neither the literal placeholder nor a raw null link may reach base.
        ->not->toContain($placeholder);
})->with([
    'quote page trait' => [__DIR__ . '/../../src/Resources/Quotes/Pages/Concerns/HasQuoteSendAction.php', '[Online Quote Link]'],
    'quote resource' => [__DIR__ . '/../../src/Resources/Quotes/QuoteResource.php', '[Online Quote Link]'],
    'invoice page trait' => [__DIR__ . '/../../src/Resources/Invoices/Pages/Concerns/HasInvoiceSendAction.php', '[Online Invoice Link]'],
    'invoice resource' => [__DIR__ . '/../../src/Resources/Invoices/InvoiceResource.php', '[Online Invoice Link]'],
]);

it('shares one stripping rule with the purchase-order helper', function () {
    $source = (string) file_get_contents(__DIR__ . '/../../src/Support/PurchaseOrderPortalLink.php');

    expect($source)->toContain('PortalMessage::stripPlaceholder(')
        ->not->toContain('preg_replace(');
});
