<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Services\InvoiceService;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\InvoiceResource;
use VentureDrake\LaravelCrmFilament\Support\FormPayload;
use VentureDrake\LaravelCrmFilament\Support\MoneyForm;

class EditInvoice extends EditRecord
{
    use Concerns\HasInvoiceMarkPaidAction;
    use Concerns\HasInvoicePortalAction;
    use Concerns\HasInvoiceSendAction;

    protected static string $resource = InvoiceResource::class;

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

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Invoice $invoice */
        $invoice = $this->record;

        foreach (['sub_total', 'discount', 'tax', 'total'] as $field) {
            $value = $data[$field] ?? null;
            if ($value !== null) {
                $data[$field] = MoneyForm::centsToForm($value);
            }
        }

        $data['adjustment'] = MoneyForm::centsToForm($data['adjustments'] ?? null);

        $data['products'] = $invoice->invoiceLines
            ->map(fn ($line) => [
                'invoice_line_id' => $line->id,
                'order_product_id' => $line->order_product_id,
                'id' => $line->product_id,
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
        $person = isset($data['person_id'])
            ? Person::find($data['person_id'])
            : $record->person;
        $organization = isset($data['organization_id'])
            ? Organization::find($data['organization_id'])
            : $record->organization;

        app(InvoiceService::class)->update(
            FormPayload::wrap($data),
            $record,
            $person,
            $organization,
        );

        // InvoiceService doesn't update discount/adjustments — write them
        // directly so the 5-rollup money row reaches the DB.
        $extras = [];
        if (array_key_exists('discount', $data)) {
            $extras['discount'] = $data['discount'] !== null
                ? (int) round(((float) $data['discount']) * 100)
                : null;
        }
        if (array_key_exists('adjustment', $data)) {
            $extras['adjustments'] = $data['adjustment'] !== null
                ? (int) round(((float) $data['adjustment']) * 100)
                : null;
        }
        if ($extras !== []) {
            $record->forceFill($extras)->save();
        }

        InvoiceResource::saveCrmCustomFields($data, $record);

        return $record->refresh();
    }

    protected function getAllRelationManagers(): array
    {
        return [];
    }
}
