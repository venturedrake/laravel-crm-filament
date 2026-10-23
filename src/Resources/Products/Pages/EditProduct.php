<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Products\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Services\ProductService;
use VentureDrake\LaravelCrmFilament\Resources\Products\ProductResource;
use VentureDrake\LaravelCrmFilament\Support\FormPayload;
use VentureDrake\LaravelCrmFilament\Support\MoneyForm;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

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
        /** @var Product $product */
        $product = $this->record;

        // The row ProductService::update() saves to (core 2.5.0). For a
        // product priced only in another currency, the default price would
        // load blanks that saving then writes over that price.
        $primaryPrice = $product->getPrimaryPrice();
        if ($primaryPrice) {
            $data['unit_price'] = MoneyForm::centsToForm($primaryPrice->unit_price);
            $data['currency'] = $primaryPrice->currency ?: $data['currency'] ?? config('laravel-crm.default_currency', 'USD');
        }

        // Priced in the product price's currency, which is the one
        // syncVariations() writes them in on save.
        $data['variations'] = ProductResource::variationRows(
            $product,
            $primaryPrice->currency ?? app('laravel-crm.settings')->get('currency', 'USD'),
        );

        return ProductResource::loadCrmCustomFieldsInto($data, $this->getRecord());
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Product $record */
        app(ProductService::class)->update($record, FormPayload::wrap($data));
        ProductResource::saveCrmCustomFields($data, $record);

        return $record->refresh();
    }

    protected function getAllRelationManagers(): array
    {
        return [];
    }
}
