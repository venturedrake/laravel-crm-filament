<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\Concerns;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Services\InvoiceService;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\InvoiceResource;
use VentureDrake\LaravelCrmFilament\Support\FormPayload;
use VentureDrake\LaravelCrmFilament\Support\OrderDrawdownPrefill;

/**
 * Convert-to-Invoice action on Order view page. Hidden when an invoice already
 * exists. Copies all order line items into the new invoice and links
 * invoice.order_id.
 */
trait HasOrderConvertToInvoiceAction
{
    protected function orderConvertToInvoiceAction(): Action
    {
        return Action::make('convertToInvoice')
            ->label(__('laravel-crm-filament::labels.actions.convert_to_invoice'))
            ->icon('heroicon-o-receipt-percent')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Convert order to invoice')
            ->modalDescription('Creates a new invoice for the quantities still outstanding on this order.')
            ->visible(fn (Order $record): bool => $record->invoices()->count() === 0)
            ->action(function (Order $record, InvoiceService $invoiceService): void {
                $payload = $this->buildInvoicePayloadFromOrder($record);

                $invoice = $invoiceService->create(
                    FormPayload::wrap($payload),
                    $record->person,
                    $record->organization,
                );

                $url = InvoiceResource::getUrl('view', ['record' => $invoice]);

                Notification::make()
                    ->title('Invoice ' . $invoice->invoice_id . ' created')
                    ->body('Order converted successfully.')
                    ->success()
                    ->actions([
                        Action::make('open')
                            ->label(__('laravel-crm-filament::labels.actions.open_invoice'))
                            ->url($url),
                    ])
                    ->send();
            });
    }

    protected function buildInvoicePayloadFromOrder(Order $record): array
    {
        // The remainder, not the full ordered quantity — see
        // OrderDrawdownPrefill. Totals are recomputed from the prefilled lines
        // so a partial invoice's header matches its own body.
        $products = OrderDrawdownPrefill::invoiceProducts($record);
        $totals = OrderDrawdownPrefill::totalsFor($products);

        return [
            'order_id' => $record->id,
            'reference' => $record->reference,
            'issue_date' => now(),
            'due_date' => now()->addDays((int) (app('laravel-crm.settings')->get('invoice_due_days', 30))),
            'currency' => $record->currency,
            'terms' => null,
            'sub_total' => $totals['sub_total'],
            // Derived from the prefilled lines, not copied off the order: on a
            // partial conversion the order's own tax covers quantities this
            // invoice does not bill.
            'tax' => $totals['tax'],
            'total' => $totals['total'],
            'user_owner_id' => $record->user_owner_id,
            'products' => $products,
        ];
    }
}
