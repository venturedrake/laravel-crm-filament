<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Orders\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Services\OrderService;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\OrderAddressTabs;
use VentureDrake\LaravelCrmFilament\Resources\Orders\OrderResource;
use VentureDrake\LaravelCrmFilament\Support\FormPayload;
use VentureDrake\LaravelCrmFilament\Support\MoneyForm;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make()
                ->button()
                ->hiddenLabel()
                ->icon('heroicon-m-eye'),
            Actions\DeleteAction::make()
                ->button()
                ->hiddenLabel()
                ->icon('heroicon-m-trash'),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Order $order */
        $order = $this->record;

        foreach (['sub_total', 'discount', 'tax', 'total'] as $field) {
            $value = $data[$field] ?? null;
            if ($value !== null) {
                $data[$field] = MoneyForm::centsToForm($value);
            }
        }

        $data['adjustment'] = MoneyForm::centsToForm($data['adjustments'] ?? null);

        $data['products'] = $order->orderProducts
            ->map(fn ($line) => [
                'order_product_id' => $line->id,
                'id' => $line->product_id,
                'quantity' => $line->quantity,
                'unit_price' => MoneyForm::centsToForm($line->price) ?? 0,
                'tax_amount' => MoneyForm::centsToForm($line->tax_amount) ?? 0,
                'amount' => MoneyForm::centsToForm($line->amount) ?? 0,
                'comments' => $line->comments,
            ])
            ->all();

        $data = array_merge($data, OrderAddressTabs::toFormData($order->addresses));

        return OrderResource::loadCrmCustomFieldsInto($data, $order);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Order $record */
        $person = isset($data['person_id'])
            ? Person::find($data['person_id'])
            : $record->person;
        $organization = isset($data['organization_id'])
            ? Organization::find($data['organization_id'])
            : $record->organization;

        $data['addresses'] = OrderAddressTabs::fromFormData($data);

        app(OrderService::class)->update(
            FormPayload::wrap($data),
            $record,
            $person,
            $organization,
            $record->client,
        );
        OrderResource::saveCrmCustomFields($data, $record);

        return $record->refresh();
    }

    protected function getAllRelationManagers(): array
    {
        return [];
    }
}
