<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\Concerns;

use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use VentureDrake\LaravelCrm\Mail\SendQuote;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrmFilament\Concerns\DownloadsPdf;

trait HasQuoteSendAction
{
    use DownloadsPdf;

    protected function quoteSendAction(): Action
    {
        return Action::make('send')
            ->label(__('laravel-crm-filament::labels.actions.send_quote'))
            ->icon('heroicon-o-paper-airplane')
            ->color('primary')
            ->modalHeading('Send quote')
            ->modalSubmitActionLabel('Send')
            ->schema(fn (Quote $record): array => [
                TextInput::make('to')
                    ->label(__('laravel-crm-filament::labels.campaign.to'))
                    ->email()
                    ->required()
                    ->default(fn () => optional($record->person)->getPrimaryEmail()?->address),
                TextInput::make('subject')
                    ->required()
                    ->default(fn () => 'Quote ' . $record->quote_id),
                Textarea::make('message')
                    ->rows(8)
                    ->default("Hi,\n\nPlease find your quote here: [Online Quote Link]\n\nThanks."),
                Checkbox::make('cc')
                    ->label(__('laravel-crm-filament::labels.campaign.send_me_a_copy')),
            ])
            ->action(function (array $data, Quote $record): void {
                $this->dispatchQuote($record, $data);

                Notification::make()
                    ->title('Quote sent')
                    ->success()
                    ->send();
            });
    }

    protected function quoteDownloadPdfAction(): Action
    {
        return $this->downloadPdfAction(
            fn (Quote $record) => $this->streamPdfDownload($record, 'quote'),
        );
    }

    protected function dispatchQuote(Quote $record, array $data): void
    {
        $signedUrl = URL::temporarySignedRoute(
            'laravel-crm.portal.quotes.show',
            now()->addDays(14),
            ['quote' => $record],
        );

        $pdfPath = $this->generateQuotePdf($record);

        Mail::send(new SendQuote([
            'to' => $data['to'],
            'subject' => $data['subject'],
            'message' => $data['message'],
            'cc' => ! empty($data['cc']) ? 1 : 0,
            'onlineQuoteLink' => $signedUrl,
            'pdf' => $pdfPath,
        ]));
    }

    protected function generateQuotePdf(Quote $record): string
    {
        return $this->renderPdfToDisk($record, 'quote');
    }
}
