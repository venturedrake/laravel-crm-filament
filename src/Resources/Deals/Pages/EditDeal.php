<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Deals\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrm\Models\Deal;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Services\DealService;
use VentureDrake\LaravelCrmFilament\Resources\Deals\DealResource;
use VentureDrake\LaravelCrmFilament\Resources\Deals\Pages\Concerns\HasDealMarkLostAction;
use VentureDrake\LaravelCrmFilament\Resources\Deals\Pages\Concerns\HasDealMarkWonAction;
use VentureDrake\LaravelCrmFilament\Resources\Deals\Pages\Concerns\HasDealReopenAction;
use VentureDrake\LaravelCrmFilament\Support\FormPayload;
use VentureDrake\LaravelCrmFilament\Support\MoneyForm;

class EditDeal extends EditRecord
{
    use HasDealMarkLostAction;
    use HasDealMarkWonAction;
    use HasDealReopenAction;

    protected static string $resource = DealResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->dealMarkWonAction(),
            $this->dealMarkLostAction(),
            $this->dealReopenAction(),
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
        $data = DealResource::loadCrmCustomFieldsInto($data, $this->record);

        // Reshape dealProducts into the repeater's expected row shape
        // (display dollar values; the model setter multiplies by 100 on save).
        $data['products'] = $this->record->dealProducts()->get()->map(fn ($line) => [
            'deal_product_id' => $line->id,
            'id' => $line->product_id,
            'price' => MoneyForm::centsToForm($line->price) ?? 0,
            'quantity' => $line->quantity,
            'amount' => MoneyForm::centsToForm($line->amount) ?? 0,
        ])->toArray();

        // Surface money fields as dollars
        if (! is_null($data['amount'] ?? null)) {
            $data['amount'] = MoneyForm::centsToForm($data['amount']);
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Deal $record */
        $person = isset($data['person_id']) ? Person::find($data['person_id']) : null;
        $organization = isset($data['organization_id']) ? Organization::find($data['organization_id']) : null;

        app(DealService::class)->update(FormPayload::wrap($data), $record, $person, $organization);
        DealResource::saveCrmCustomFields($data, $record);

        return $record->refresh();
    }

    protected function getAllRelationManagers(): array
    {
        return [];
    }
}
