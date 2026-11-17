<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Services\InvoiceService;
use VentureDrake\LaravelCrm\Services\PersonService;
use VentureDrake\LaravelCrmFilament\Concerns\RefreshesMoneyTotalsAfterSave;
use VentureDrake\LaravelCrmFilament\Concerns\SurfacesServiceValidation;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\InvoiceResource;
use VentureDrake\LaravelCrmFilament\Support\FormPayload;
use VentureDrake\LaravelCrmFilament\Support\MoneyForm;

class EditInvoice extends EditRecord
{
    use Concerns\HasInvoiceMarkPaidAction;
    use Concerns\HasInvoicePortalAction;
    use Concerns\HasInvoiceSendAction;
    use RefreshesMoneyTotalsAfterSave;
    use SurfacesServiceValidation;

    protected static string $resource = InvoiceResource::class;

    /**
     * Core's services already run in a transaction; this one also covers
     * the contact links and custom field values saved around them.
     */
    protected ?bool $hasDatabaseTransactions = true;

    protected function getHeaderActions(): array
    {
        return [
            $this->invoiceMarkPaidAction(),
            Actions\ViewAction::make()
                ->button()
                ->hiddenLabel()
                ->icon('heroicon-m-eye'),
            $this->invoiceSendAction(),
            $this->invoiceDownloadPdfAction(),
            $this->invoicePortalAction(),
            Actions\DeleteAction::make()
                ->button()
                ->hiddenLabel()
                ->icon('heroicon-m-trash'),
        ];
    }

    /**
     * The form has no discount or adjustment (MoneyTotalsRow::make(withAdjustments: false)).
     *
     * @return list<string>
     */
    protected function moneyTotalsStatePaths(): array
    {
        return ['sub_total', 'tax', 'total'];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Invoice $invoice */
        $invoice = $this->record;

        foreach (['discount', 'tax', 'total'] as $field) {
            $value = $data[$field] ?? null;
            if ($value !== null) {
                $data[$field] = MoneyForm::centsToForm($value);
            }
        }

        // The columns are `subtotal` and `adjustments`; the form's fields are
        // `sub_total` and `adjustment`.
        $data['sub_total'] = MoneyForm::centsToForm($data['subtotal'] ?? null);
        $data['adjustment'] = MoneyForm::centsToForm($data['adjustments'] ?? null);

        $data['products'] = $invoice->invoiceLines()
            ->orderBy('order')
            ->orderBy('id')
            ->get()
            ->map(fn ($line) => [
                'invoice_line_id' => $line->id,
                'order_product_id' => $line->order_product_id,
                'id' => $line->product_id,
                'product_variation_id' => $line->product_variation_id,
                'quantity' => $line->quantity,
                'unit_price' => MoneyForm::centsToForm($line->price) ?? 0,
                'tax_amount' => MoneyForm::centsToForm($line->tax_amount) ?? 0,
                'amount' => MoneyForm::centsToForm($line->amount) ?? 0,
                'comments' => $line->comments,
            ])
            ->all();

        return InvoiceResource::loadCrmCustomFieldsInto($data, $this->getRecord());
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Invoice $record */
        // Lock the row first, as core's own edit does, so a payment or an
        // accept landing while this waits is seen by the service's checks.
        app(InvoiceService::class)->lockForEdit($record);

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
            fn () => app(InvoiceService::class)->update(
                FormPayload::wrap($data),
                $record,
                $person,
                $organization,
            ),
        );

        InvoiceResource::saveCrmCustomFields($data, $record);

        return $record->refresh();
    }

    protected function getAllRelationManagers(): array
    {
        return [];
    }
}
