<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages;

use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrm\Services\PersonService;
use VentureDrake\LaravelCrm\Services\QuoteService;
use VentureDrake\LaravelCrmFilament\Concerns\SurfacesServiceValidation;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\QuoteResource;
use VentureDrake\LaravelCrmFilament\Support\FormPayload;
use VentureDrake\LaravelCrmFilament\Support\MoneyForm;

class EditQuote extends EditRecord
{
    use Concerns\HasQuoteAcceptAction;
    use Concerns\HasQuotePortalAction;
    use Concerns\HasQuoteRejectAction;
    use Concerns\HasQuoteSendAction;
    use Concerns\HasQuoteUnacceptAction;
    use SurfacesServiceValidation;

    protected static string $resource = QuoteResource::class;

    /**
     * Core's services already run in a transaction; this one also covers
     * the contact links and custom field values saved around them.
     */
    protected ?bool $hasDatabaseTransactions = true;

    /**
     * An accepted or ordered quote is locked (core 2.7.0): opening its edit
     * page goes to the View page with the reason, as core's QuoteEdit does.
     */
    public function mount(int | string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $this->authorizeAccess();

        /** @var Quote $quote */
        $quote = $this->record;

        if ($reason = $quote->lockReason()) {
            Notification::make()
                ->warning()
                ->title($reason)
                ->send();

            $this->redirect(QuoteResource::getUrl('view', ['record' => $quote]));

            return;
        }

        $this->fillForm();

        $this->previousUrl = url()->previous();
    }

    /**
     * Update permission only. QuoteResource::canEdit() also refuses a locked
     * quote, and Filament re-checks this on every request, so a quote
     * accepted while the form is open would 403 on save. mount() turns a
     * locked quote away, and QuoteService::update() refuses one locked since,
     * which SurfacesServiceValidation shows as a notification.
     */
    protected function authorizeAccess(): void
    {
        abort_unless(QuoteResource::can('update', $this->getRecord()), 403);
    }

    protected function getHeaderActions(): array
    {
        return [
            // Accepting locks the quote, so the form it was accepted from
            // could no longer be saved: go to the View page instead.
            $this->quoteAcceptAction()
                ->after(fn () => $this->redirect(QuoteResource::getUrl('view', ['record' => $this->getRecord()]))),
            $this->quoteRejectAction(),
            ...$this->quoteUnacceptActions(),
            Actions\ViewAction::make()
                ->button()
                ->hiddenLabel()
                ->icon('heroicon-m-eye'),
            $this->quoteSendAction(),
            $this->quotePortalAction(),
            QuoteResource::deleteBlockedTooltip(Actions\DeleteAction::make())
                ->button()
                ->hiddenLabel()
                ->icon('heroicon-m-trash'),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Quote $quote */
        $quote = $this->record;

        foreach (['sub_total', 'discount', 'tax', 'total'] as $field) {
            $value = $data[$field] ?? null;
            if ($value !== null) {
                $data[$field] = MoneyForm::centsToForm($value);
            }
        }

        $data['adjustment'] = MoneyForm::centsToForm($data['adjustments'] ?? null);

        $data['products'] = $quote->quoteProducts()
            ->orderBy('order')
            ->orderBy('id')
            ->get()
            ->map(fn ($line) => [
                'quote_product_id' => $line->id,
                'id' => $line->product_id,
                'product_variation_id' => $line->product_variation_id,
                'quantity' => $line->quantity,
                'unit_price' => MoneyForm::centsToForm($line->price) ?? 0,
                'tax_amount' => MoneyForm::centsToForm($line->tax_amount) ?? 0,
                'amount' => MoneyForm::centsToForm($line->amount) ?? 0,
                'comments' => $line->comments,
            ])
            ->all();

        return QuoteResource::loadCrmCustomFieldsInto($data, $quote);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Quote $record */
        // Lock the row first, as core's own edit does, so a payment or an
        // accept landing while this waits is seen by the service's checks.
        app(QuoteService::class)->lockForEdit($record);

        // A cleared select arrives as null and must unlink; only a field the
        // form did not submit at all keeps the current link.
        $person = array_key_exists('person_id', $data)
            ? Person::find($data['person_id'])
            : $record->person;
        $organization = array_key_exists('organization_id', $data)
            ? Organization::find($data['organization_id'])
            : $record->organization;

        // Saved together, the person and organization become related
        // contacts of each other, as core 2.5.0 does (#94).
        app(PersonService::class)->linkToOrganization($person, $organization);

        $this->surfacingServiceValidation(
            fn () => app(QuoteService::class)->update(
                FormPayload::wrap($data),
                $record,
                $person,
                $organization,
                $record->client,
            ),
        );
        QuoteResource::saveCrmCustomFields($data, $record);

        return $record->refresh();
    }

    protected function getAllRelationManagers(): array
    {
        return [];
    }
}
