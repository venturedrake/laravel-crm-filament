<?php

namespace VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\Pages\Concerns;

use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Mail;
use VentureDrake\LaravelCrm\Mail\SendPurchaseOrder;
use VentureDrake\LaravelCrm\Models\PurchaseOrder;
use VentureDrake\LaravelCrmFilament\Concerns\DownloadsPdf;
use VentureDrake\LaravelCrmFilament\Support\PurchaseOrderPortalLink;

trait HasPurchaseOrderSendAction
{
    use DownloadsPdf;

    protected function purchaseOrderSendAction(): Action
    {
        return Action::make('send')
            ->label(__('laravel-crm-filament::labels.actions.send_purchase_order'))
            ->icon('heroicon-o-paper-airplane')
            ->color('primary')
            ->modalHeading('Send purchase order')
            ->modalSubmitActionLabel('Send')
            ->schema(fn (PurchaseOrder $record): array => [
                TextInput::make('to')
                    ->label(__('laravel-crm-filament::labels.campaign.to'))
                    ->email()
                    ->required(),
                TextInput::make('subject')
                    ->required()
                    ->default(fn () => 'Purchase Order ' . $record->purchase_order_id),
                Textarea::make('message')
                    ->rows(8)
                    ->default(fn (): string => PurchaseOrderPortalLink::defaultMessage()),
                Checkbox::make('cc')
                    ->label(__('laravel-crm-filament::labels.campaign.send_me_a_copy')),
            ])
            ->action(function (array $data, PurchaseOrder $record): void {
                $this->dispatchPurchaseOrder($record, $data);

                Notification::make()
                    ->title('Purchase order sent')
                    ->success()
                    ->send();
            });
    }

    protected function purchaseOrderDownloadPdfAction(): Action
    {
        return $this->downloadPdfAction(
            fn (PurchaseOrder $record) => $this->streamPdfDownload($record, 'purchaseorder'),
        );
    }

    protected function dispatchPurchaseOrder(PurchaseOrder $record, array $data): void
    {
        $signedUrl = PurchaseOrderPortalLink::signedFor($record);

        $message = (string) $data['message'];

        if ($signedUrl === null) {
            $message = PurchaseOrderPortalLink::stripPlaceholder($message);
        }

        $pdfPath = $this->generatePurchaseOrderPdf($record);

        Mail::send(new SendPurchaseOrder([
            'to' => $data['to'],
            'subject' => $data['subject'],
            'message' => $message,
            'cc' => ! empty($data['cc']) ? 1 : 0,
            'onlinePurchaseOrderLink' => $signedUrl ?? '',
            'pdf' => $pdfPath,
        ]));
    }

    protected function generatePurchaseOrderPdf(PurchaseOrder $record): string
    {
        return $this->renderPdfToDisk($record, 'purchaseorder');
    }
}
