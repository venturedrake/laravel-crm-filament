<?php

namespace VentureDrake\LaravelCrmFilament\Support;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Exception\ExceptionInterface as MimeExceptionInterface;
use VentureDrake\LaravelCrm\Http\Rules\EmailAddressList;

/**
 * The parts the quote, invoice and purchase-order Send modals share, as core
 * 2.7.0's QuoteSend / InvoiceSend / PurchaseOrderSend do them.
 *
 *  - The To field takes one or more addresses separated by commas or
 *    semicolons, each checked (`EmailAddressList`), so a bad address is a
 *    field error before the PDF is rendered rather than a 500 from Symfony
 *    Mime when the mailable sets its To header.
 *  - A transport or MIME failure is logged and shown as a danger
 *    notification, and the modal stays open with what was typed.
 */
class DocumentMail
{
    /**
     * @param  \Closure(): ?string  $default
     */
    public static function toField(\Closure $default): TextInput
    {
        return TextInput::make('to')
            ->label(__('laravel-crm-filament::labels.campaign.to'))
            ->helperText(__('laravel-crm-filament::labels.send.to_hint'))
            ->required()
            ->rule(new EmailAddressList)
            ->default($default);
    }

    /**
     * The addresses typed into the To field.
     *
     * @return list<string>
     */
    public static function recipients(?string $to): array
    {
        return EmailAddressList::parse($to);
    }

    /**
     * Send $mailable, or tell the user why it did not go and halt $action.
     *
     * Returns only when the mail was handed to the transport; the caller then
     * records the document as sent.
     */
    public static function sendOrHalt(Mailable $mailable, ?Action $action, string $type, Model $record): void
    {
        try {
            Mail::send($mailable);
        } catch (TransportExceptionInterface | MimeExceptionInterface $exception) {
            Log::error("[laravel-crm-filament] {$type} send failed", [
                'exception' => $exception,
                "{$type}_id" => $record->getKey(),
            ]);

            Notification::make()
                ->danger()
                ->title(ucfirst(__('laravel-crm::lang.email_could_not_be_sent', [
                    'reason' => Str::limit($exception->getMessage(), 150),
                ])))
                ->persistent()
                ->send();

            $action?->halt();

            throw $exception;
        }
    }
}
