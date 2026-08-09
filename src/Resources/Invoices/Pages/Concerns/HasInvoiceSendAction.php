<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\Concerns;

use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use VentureDrake\LaravelCrm\Mail\SendInvoice;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrmFilament\Concerns\DownloadsPdf;

trait HasInvoiceSendAction
{
    use DownloadsPdf;

    protected function invoiceSendAction(): Action
    {
        return Action::make('send')
            ->label(__('laravel-crm-filament::labels.actions.send_invoice'))
            ->icon('heroicon-o-paper-airplane')
            ->color('primary')
            ->modalHeading('Send invoice')
            ->modalSubmitActionLabel('Send')
            ->schema(fn (Invoice $record): array => [
                TextInput::make('to')
                    ->label(__('laravel-crm-filament::labels.campaign.to'))
                    ->email()
                    ->required()
                    ->default(fn () => optional($record->person)->getPrimaryEmail()?->address),
                TextInput::make('subject')
                    ->required()
                    ->default(fn () => 'Invoice ' . $record->invoice_id),
                Textarea::make('message')
                    ->rows(8)
                    ->default("Hi,\n\nPlease find your invoice here: [Online Invoice Link]\n\nThanks."),
                Checkbox::make('cc')
                    ->label(__('laravel-crm-filament::labels.campaign.send_me_a_copy')),
            ])
            ->action(function (array $data, Invoice $record): void {
                $this->dispatchInvoice($record, $data);

                Notification::make()
                    ->title('Invoice sent')
                    ->success()
                    ->send();
            });
    }

    protected function invoiceDownloadPdfAction(): Action
    {
        return $this->downloadPdfAction(
            fn (Invoice $record) => $this->streamPdfDownload($record, 'invoice'),
        );
    }

    protected function dispatchInvoice(Invoice $record, array $data): void
    {
        $signedUrl = URL::temporarySignedRoute(
            'laravel-crm.portal.invoices.show',
            now()->addDays(14),
            ['invoice' => $record],
        );

        $pdfPath = $this->generateInvoicePdf($record);

        Mail::send(new SendInvoice([
            'to' => $data['to'],
            'subject' => $data['subject'],
            'message' => $data['message'],
            'cc' => ! empty($data['cc']) ? 1 : 0,
            'onlineInvoiceLink' => $signedUrl,
            'pdf' => $pdfPath,
        ]));
    }

    protected function generateInvoicePdf(Invoice $record): string
    {
        return $this->renderPdfToDisk($record, 'invoice');
    }
}
