<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Orders\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Services\OrderService;
use VentureDrake\LaravelCrm\Services\PersonService;
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

        // Without "Unallocated" on offer, an order with no owner opens on the
        // signed-in user, as core's OrderEdit does.
        if (empty($data['user_owner_id']) && ! OrderResource::orderForm()->allowsUnallocatedOwner()) {
            $data['user_owner_id'] = auth()->id();
        }

        $data['products'] = $order->orderProducts()
            ->orderBy('order')
            ->orderBy('id')
            ->get()
            ->map(fn ($line) => [
                'order_product_id' => $line->id,
                'id' => $line->product_id,
                'product_variation_id' => $line->product_variation_id,
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
        // A cleared select arrives as null and must unlink; only a field the
        // form did not submit at all keeps the current link.
        $person = array_key_exists('person_id', $data)
            ? Person::find($data['person_id'])
            : $record->person;
        $organization = array_key_exists('organization_id', $data)
            ? Organization::find($data['organization_id'])
            : $record->organization;

        $form = OrderResource::orderForm();

        $data['addresses'] = OrderAddressTabs::fromFormData($data, $form->addressTypes());

        // Saved together, the person and organization become related
        // contacts of each other, as core 2.5.0 does (#94).
        app(PersonService::class)->linkToOrganization($person, $organization);

        // With the form options, a hidden description, hidden labels and an
        // address of a hidden type are left as stored.
        app(OrderService::class)->update(
            FormPayload::wrap($data),
            $record,
            $person,
            $organization,
            $record->client,
            form: $form,
        );

        if ($form->shows('custom_fields')) {
            OrderResource::saveCrmCustomFields($data, $record);
        }

        return $record->refresh();
    }

    protected function getAllRelationManagers(): array
    {
        return [];
    }
}
