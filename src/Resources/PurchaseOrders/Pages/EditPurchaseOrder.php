<?php

namespace VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\PurchaseOrder;
use VentureDrake\LaravelCrm\Services\PurchaseOrderService;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\PurchaseOrderResource;
use VentureDrake\LaravelCrmFilament\Support\FormPayload;
use VentureDrake\LaravelCrmFilament\Support\MoneyForm;

class EditPurchaseOrder extends EditRecord
{
    use Concerns\HasPurchaseOrderSendAction;

    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make()
                ->button()
                ->hiddenLabel()
                ->icon('heroicon-m-eye'),
            $this->purchaseOrderSendAction(),
            Actions\DeleteAction::make()
                ->button()
                ->hiddenLabel()
                ->icon('heroicon-m-trash'),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var PurchaseOrder $po */
        $po = $this->record;

        foreach (['sub_total', 'discount', 'tax', 'total'] as $field) {
            $value = $data[$field] ?? null;
            if ($value !== null) {
                $data[$field] = MoneyForm::centsToForm($value);
            }
        }

        $data['adjustment'] = MoneyForm::centsToForm($data['adjustments'] ?? null);

        $data['products'] = $po->purchaseOrderLines
            ->map(fn ($line) => [
                'purchase_order_line_id' => $line->id,
                'order_product_id' => $line->order_product_id,
                'id' => $line->product_id,
                'quantity' => $line->quantity,
                'unit_price' => MoneyForm::centsToForm($line->price) ?? 0,
                'tax_amount' => MoneyForm::centsToForm($line->tax_amount) ?? 0,
                'amount' => MoneyForm::centsToForm($line->amount) ?? 0,
                'comments' => $line->comments,
            ])
            ->all();

        return PurchaseOrderResource::loadCrmCustomFieldsInto($data, $this->getRecord());
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        // PurchaseOrderService::update reads $request->purchaseOrderLines, create reads $request->products.
        $data['purchaseOrderLines'] = $data['products'] ?? [];

        /** @var PurchaseOrder $record */
        $person = isset($data['person_id'])
            ? Person::find($data['person_id'])
            : $record->person;
        $organization = isset($data['organization_id'])
            ? Organization::find($data['organization_id'])
            : $record->organization;

        app(PurchaseOrderService::class)->update(
            FormPayload::wrap($data),
            $record,
            $person,
            $organization,
        );

        // Persist discount + adjustments (the service doesn't write them).
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

        PurchaseOrderResource::saveCrmCustomFields($data, $record);

        return $record->refresh();
    }

    protected function getAllRelationManagers(): array
    {
        return [];
    }
}
