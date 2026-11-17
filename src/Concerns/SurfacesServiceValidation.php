<?php

namespace VentureDrake\LaravelCrmFilament\Concerns;

use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Validation\ValidationException;

/**
 * Show a ValidationException thrown by a core service on a Filament page.
 *
 * Core 2.7.0's services refuse some saves themselves: a locked quote
 * (`quote`), repricing a paid invoice (`line_items`), a delivery line raised
 * past its order line (`products.N.quantity`) and a lead that is already
 * converted (`lead_id`). Those keys are not under the form's `data.*` state
 * path, so Livewire put them in the error bag where no field shows them and
 * the save looked like it did nothing.
 *
 * The first message goes out as a danger notification, and the page halts
 * with the transaction rolled back, so nothing the save did before the
 * service refused it (contacts linked, custom fields written) is kept. Use
 * it on pages with `$hasDatabaseTransactions = true`.
 */
trait SurfacesServiceValidation
{
    /**
     * @template T
     *
     * @param  callable(): T  $save
     * @return T
     *
     * @throws Halt
     */
    protected function surfacingServiceValidation(callable $save): mixed
    {
        try {
            return $save();
        } catch (ValidationException $exception) {
            Notification::make()
                ->danger()
                ->title(self::firstServiceValidationMessage($exception))
                ->persistent()
                ->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }

    public static function firstServiceValidationMessage(ValidationException $exception): string
    {
        $messages = $exception->errors();
        $first = reset($messages);

        return is_array($first) && $first !== [] ? (string) reset($first) : $exception->getMessage();
    }
}
