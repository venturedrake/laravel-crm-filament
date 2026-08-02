<?php

use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrmFilament\Concerns\DownloadsPdf;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\Concerns\HasInvoiceSendAction;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\ViewInvoice;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\Concerns\HasOrderConvertToDeliveryAction;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\Concerns\HasOrderConvertToInvoiceAction;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\Concerns\HasOrderConvertToPurchaseOrderAction;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\ViewOrder;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\Pages\Concerns\HasPurchaseOrderSendAction;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\Concerns\HasQuoteConvertToOrderAction;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\Concerns\HasQuoteSendAction;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\ViewQuote;

/**
 * Structural assertions for the v0.5 pipeline conversion + download actions.
 * Uses reflection to avoid spinning up a full Livewire/Filament boot just to
 * verify which traits are wired into each ViewRecord page.
 */
function pageUsesTrait(string $page, string $trait): bool
{
    return in_array($trait, class_uses_recursive($page), true);
}

it('exposes Convert-to-Order on the Quote view page', function () {
    expect(pageUsesTrait(
        ViewQuote::class,
        HasQuoteConvertToOrderAction::class,
    ))->toBeTrue();
});

it('exposes Download-PDF on the Quote view page', function () {
    expect(method_exists(ViewQuote::class, 'quoteDownloadPdfAction'))->toBeTrue();
});

it('exposes Convert-to-Invoice on the Order view page', function () {
    // Asserted on the page's header actions, not just the trait: the trait was
    // fully implemented but never referenced from getHeaderActions() until
    // US-010, which a class_uses_recursive() check could not catch.
    expect(pageUsesTrait(
        ViewOrder::class,
        HasOrderConvertToInvoiceAction::class,
    ))->toBeTrue();

    $instance = (new ReflectionClass(ViewOrder::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(ViewOrder::class, 'getHeaderActions');
    $method->setAccessible(true);

    $names = array_map(fn ($action) => $action->getName(), $method->invoke($instance));

    expect($names)->toContain('convertToInvoice');
});

it('exposes Convert-to-Delivery on the Order view page', function () {
    expect(pageUsesTrait(
        ViewOrder::class,
        HasOrderConvertToDeliveryAction::class,
    ))->toBeTrue();
});

it('exposes Convert-to-PurchaseOrder on the Order view page', function () {
    expect(pageUsesTrait(
        ViewOrder::class,
        HasOrderConvertToPurchaseOrderAction::class,
    ))->toBeTrue();
});

it('exposes Download-PDF on the Invoice view page', function () {
    expect(method_exists(ViewInvoice::class, 'invoiceDownloadPdfAction'))->toBeTrue();
});

it('exposes Download-PDF on the PurchaseOrder view page', function () {
    expect(method_exists(ViewPurchaseOrder::class, 'purchaseOrderDownloadPdfAction'))->toBeTrue();
});

it('routes all Send concerns through the shared DownloadsPdf trait', function () {
    $sendConcerns = [
        HasQuoteSendAction::class,
        HasInvoiceSendAction::class,
        HasPurchaseOrderSendAction::class,
    ];

    foreach ($sendConcerns as $sendConcern) {
        expect(in_array(
            DownloadsPdf::class,
            class_uses_recursive($sendConcern),
            true,
        ))->toBeTrue("$sendConcern should use DownloadsPdf");
    }
});

it('registers the expected header actions on each View page', function () {
    $expectations = [
        ViewQuote::class => ['send', 'downloadPdf', 'previewPortal', 'convertToOrder'],
        ViewOrder::class => ['convertToInvoice', 'convertToDelivery', 'convertToPurchaseOrder'],
        ViewInvoice::class => ['send', 'downloadPdf', 'previewPortal'],
        ViewPurchaseOrder::class => ['send', 'downloadPdf'],
    ];

    foreach ($expectations as $page => $expected) {
        $instance = (new ReflectionClass($page))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($page, 'getHeaderActions');
        $method->setAccessible(true);
        $actions = $method->invoke($instance);

        $names = array_map(fn ($action) => $action->getName(), $actions);

        foreach ($expected as $name) {
            expect($names)->toContain($name);
        }
    }
});

it('gates ConvertToOrder visibility on quote.accepted_at being non-null', function () {
    $page = new ViewQuote;
    // ReflectionClass to access the protected trait method without booting a Livewire page.
    $reflection = new ReflectionMethod(ViewQuote::class, 'quoteConvertToOrderAction');
    $reflection->setAccessible(true);
    $action = $reflection->invoke($page);

    $open = new Quote;
    $open->title = 'Pending';
    $open->accepted_at = null;
    // setRelation so $record->orders()->count() does not need a DB connection.
    $open->setRelation('orders', collect());

    $accepted = new Quote;
    $accepted->title = 'Accepted';
    $accepted->accepted_at = now();
    $accepted->setRelation('orders', collect());

    $action->record($open);
    expect($action->isVisible())->toBeFalse();

    $action->record($accepted);
    expect($action->isVisible())->toBeTrue();
});
