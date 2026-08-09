<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrm\Services\QuoteService;
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

    protected static string $resource = QuoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->quoteAcceptAction(),
            $this->quoteRejectAction(),
            ...$this->quoteUnacceptActions(),
            Actions\ViewAction::make()
                ->button()
                ->hiddenLabel()
                ->icon('heroicon-m-eye'),
            $this->quoteSendAction(),
            $this->quotePortalAction(),
            Actions\DeleteAction::make()
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

        $data['products'] = $quote->quoteProducts
            ->map(fn ($line) => [
                'quote_product_id' => $line->id,
                'id' => $line->product_id,
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
        $person = isset($data['person_id'])
            ? Person::find($data['person_id'])
            : $record->person;
        $organization = isset($data['organization_id'])
            ? Organization::find($data['organization_id'])
            : $record->organization;

        app(QuoteService::class)->update(
            FormPayload::wrap($data),
            $record,
            $person,
            $organization,
            $record->client,
        );
        QuoteResource::saveCrmCustomFields($data, $record);

        return $record->refresh();
    }

    protected function getAllRelationManagers(): array
    {
        return [];
    }
}
