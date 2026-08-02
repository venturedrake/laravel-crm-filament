<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\Concerns;

use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\OrderProduct;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Services\PurchaseOrderService;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\PurchaseOrderResource;
use VentureDrake\LaravelCrmFilament\Support\FormPayload;

/**
 * Convert-to-PurchaseOrder action on the Order view page.
 *
 * Mirrors base's `laravel-crm.purchase-orders.store-multiple`
 * (PurchaseOrderController@storeMultiple): the user picks which order lines to
 * raise, optionally splits them per supplier, and one purchase order is created
 * per supplier group. Base collects the supplier as a per-line `organization_id`
 * in its Livewire form and skips lines with none; here the unassigned lines are
 * still raised, as a single purchase order against the order's own person /
 * organization, so no selected line is silently dropped.
 *
 * Line pricing picks the supplier price (Product->getDefaultPrice()->cost_per_unit)
 * when available, falling back to the product's default unit_price and then to
 * the order line's own price.
 */
trait HasOrderConvertToPurchaseOrderAction
{
    protected function orderConvertToPurchaseOrderAction(): Action
    {
        return Action::make('convertToPurchaseOrder')
            ->label(__('laravel-crm-filament::labels.actions.convert_to_purchase_order'))
            ->icon('heroicon-o-clipboard-document-list')
            ->color('success')
            ->modalHeading('Create purchase order from order')
            ->modalDescription('Copies the selected line items using each product\'s supplier price (or unit price as fallback).')
            ->modalSubmitActionLabel(__('laravel-crm-filament::labels.actions.create_purchase_orders'))
            ->schema(fn (Order $record): array => $this->purchaseOrderConversionSchema($record))
            ->action(function (Order $record, array $data, PurchaseOrderService $purchaseOrderService): void {
                $payloads = $this->buildPurchaseOrderPayloadsFromOrder($record, $data);

                if ($payloads === []) {
                    Notification::make()
                        ->title(__('laravel-crm-filament::labels.notifications.no_line_items_selected'))
                        ->warning()
                        ->send();

                    return;
                }

                $purchaseOrders = [];

                foreach ($payloads as $payload) {
                    $supplier = $payload['organization_id']
                        ? Organization::find($payload['organization_id'])
                        : null;

                    $purchaseOrders[] = $purchaseOrderService->create(
                        FormPayload::wrap($payload),
                        $supplier ? null : $record->person,
                        $supplier ?? $record->organization,
                    );
                }

                $first = $purchaseOrders[0];
                $url = PurchaseOrderResource::getUrl('view', ['record' => $first]);

                Notification::make()
                    ->title(count($purchaseOrders) === 1
                        ? 'Purchase order ' . $first->purchase_order_id . ' created'
                        : count($purchaseOrders) . ' purchase orders created')
                    ->body('Order converted to purchase order.')
                    ->success()
                    ->actions([
                        Action::make('open')
                            ->label(__('laravel-crm-filament::labels.actions.open_purchase_order'))
                            ->url($url),
                    ])
                    ->send();
            });
    }

    /**
     * Modal schema: which order lines to raise, whether to split them per
     * supplier, and — when splitting — the supplier for each selected line.
     *
     * @return array<int, mixed>
     */
    protected function purchaseOrderConversionSchema(Order $record): array
    {
        $options = $this->purchaseOrderLineOptions($record);

        return [
            CheckboxList::make('line_items')
                ->label(__('laravel-crm-filament::labels.money.line_items'))
                ->options($options)
                ->default(array_keys($options))
                ->bulkToggleable()
                ->columns(1)
                ->required(),

            Toggle::make('split_by_supplier')
                ->label(__('laravel-crm-filament::labels.actions.split_by_supplier'))
                ->helperText(__('laravel-crm-filament::labels.misc.split_by_supplier_helper'))
                ->default(false)
                ->live(),

            Section::make(__('laravel-crm-filament::labels.money.suppliers'))
                ->schema(array_map(
                    fn (int $orderProductId, string $label) => Select::make('suppliers.' . $orderProductId)
                        ->label($label)
                        ->options(fn () => Organization::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->preload(),
                    array_map('intval', array_keys($options)),
                    array_values($options),
                ))
                ->visible(fn (Get $get): bool => (bool) $get('split_by_supplier')),
        ];
    }

    /**
     * Order line options keyed by order product id, as
     * "Product name × quantity" (falling back to the line id when the product
     * has been deleted).
     *
     * @return array<int, string>
     */
    protected function purchaseOrderLineOptions(Order $record): array
    {
        $options = [];

        foreach ($record->orderProducts as $orderProduct) {
            $name = optional($orderProduct->product)->name ?? '#' . $orderProduct->id;

            $options[$orderProduct->id] = $name . ' × ' . (float) $orderProduct->quantity;
        }

        return $options;
    }

    /**
     * One payload per supplier group. With `split_by_supplier` off (or with no
     * supplier chosen for any line) that is a single payload holding every
     * selected line; with it on, the selected lines are grouped by their chosen
     * supplier organization, with the unassigned lines forming their own group.
     *
     * @param  array<string, mixed>  $data  the modal payload
     * @return array<int, array<string, mixed>>
     */
    protected function buildPurchaseOrderPayloadsFromOrder(Order $record, array $data = []): array
    {
        $selected = array_key_exists('line_items', $data) && $data['line_items'] !== null
            ? array_map('intval', (array) $data['line_items'])
            : null;

        $splitBySupplier = (bool) ($data['split_by_supplier'] ?? false);

        /** @var array<int|string, mixed> $supplierIds */
        $supplierIds = (array) ($data['suppliers'] ?? []);

        $groups = [];

        foreach ($record->orderProducts as $orderProduct) {
            if ($selected !== null && ! in_array((int) $orderProduct->id, $selected, true)) {
                continue;
            }

            $supplierId = $splitBySupplier
                ? ($supplierIds[$orderProduct->id] ?? null)
                : null;

            // '' groups every line with no supplier into a single purchase
            // order raised against the order's own person / organization.
            $key = ($supplierId === null || $supplierId === '') ? '' : (int) $supplierId;

            $groups[$key][] = $this->purchaseOrderLineFromOrderProduct($orderProduct);
        }

        $payloads = [];

        foreach ($groups as $key => $products) {
            $payloads[] = array_merge(
                $this->purchaseOrderHeaderFromOrder($record),
                [
                    'organization_id' => $key === '' ? null : (int) $key,
                    'products' => $products,
                ],
            );
        }

        return $payloads;
    }

    /**
     * Purchase order header fields shared by every group.
     *
     * @return array<string, mixed>
     */
    protected function purchaseOrderHeaderFromOrder(Order $record): array
    {
        return [
            'order_id' => $record->id,
            'reference' => $record->reference,
            'issue_date' => now(),
            'delivery_date' => now()->addDays(14),
            'currency' => $record->currency,
            'delivery_type' => 'collect',
            'delivery_instructions' => null,
            'terms' => null,
            'user_owner_id' => $record->user_owner_id,
            // Totals deliberately left blank; PurchaseOrderService recomputes
            // them from $products when $request->total is empty.
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function purchaseOrderLineFromOrderProduct(OrderProduct $orderProduct): array
    {
        $unitPrice = $this->resolveSupplierUnitPrice($orderProduct->product_id, $orderProduct->price);
        $quantity = (float) $orderProduct->quantity;

        return [
            'id' => $orderProduct->product_id,
            'order_product_id' => $orderProduct->id,
            'quantity' => $orderProduct->quantity,
            'unit_price' => $unitPrice,
            'amount' => $unitPrice * $quantity,
            'comments' => $orderProduct->comments,
        ];
    }

    private function resolveSupplierUnitPrice(?int $productId, ?int $orderProductPriceCents): float
    {
        if ($productId && $product = Product::find($productId)) {
            $price = $product->getDefaultPrice();

            if ($price && $price->cost_per_unit) {
                return $price->cost_per_unit / 100;
            }

            if ($price && $price->unit_price) {
                return $price->unit_price / 100;
            }
        }

        return $orderProductPriceCents !== null ? $orderProductPriceCents / 100 : 0;
    }
}
