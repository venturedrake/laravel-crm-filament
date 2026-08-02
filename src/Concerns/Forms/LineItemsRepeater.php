<?php

namespace VentureDrake\LaravelCrmFilament\Concerns\Forms;

use Filament\Forms;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Grid;
use Illuminate\Support\Facades\Auth;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\TaxRate;
use VentureDrake\LaravelCrm\Services\ProductService;
use VentureDrake\LaravelCrmFilament\Support\FormPayload;

/**
 * Shared products line-items Repeater used by Deal, Quote, Order, Invoice,
 * and PurchaseOrder create/edit forms.
 *
 * Row layout (mirroring core CRM's tfoot stacked rows):
 *   row 1 — hidden FK + product Select (full width)
 *   row 2 — nested Grid: unit_price + quantity + tax_amount + amount
 *           (3-col on Deal — no tax_amount)
 *   row 3 — comments Textarea (full width, 2 rows)
 *
 * The hidden foreign-key column name differs per entity (`deal_product_id`,
 * `quote_product_id`, `order_product_id`, `invoice_line_id`,
 * `purchase_order_line_id`). The unit-price form field name also differs
 * across the family — Deal uses `price`; Quote/Order/Invoice/PurchaseOrder
 * use `unit_price`. tax_amount is only added when $priceField === 'unit_price'.
 *
 * Closure callbacks are typed loosely (no Forms\Get / Forms\Set typehints)
 * because the nested Schemas\Components\Grid causes Filament to pass
 * Schemas\Components\Utilities\Get / Set instead of the Forms namespace
 * variants. Filament resolves these by parameter name, so the untyped
 * shape works for both.
 */
class LineItemsRepeater
{
    public static function products(string $fkColumn = 'deal_product_id', string $priceField = 'price'): Forms\Components\Repeater
    {
        $row2Fields = [
            Forms\Components\TextInput::make($priceField)
                ->label(__('laravel-crm-filament::labels.money.unit_price'))
                ->numeric()
                ->live()
                ->afterStateUpdated(function ($state, $get, $set) use ($priceField) {
                    self::recalcRow($get, $set, $priceField);
                    self::recalcFormTotals($get, $set);
                }),
            Forms\Components\TextInput::make('quantity')
                ->numeric()
                ->default(1)
                ->minValue(0)
                ->live()
                ->afterStateUpdated(function ($state, $get, $set) use ($priceField) {
                    self::recalcRow($get, $set, $priceField);
                    self::recalcFormTotals($get, $set);
                }),
        ];

        if ($priceField === 'unit_price') {
            $row2Fields[] = Forms\Components\TextInput::make('tax_amount')
                ->label(__('laravel-crm-filament::labels.money.tax'))
                ->numeric()
                ->readOnly();
        }

        $row2Fields[] = Forms\Components\TextInput::make('amount')
            ->label(__('laravel-crm-filament::labels.money.amount'))
            ->numeric()
            ->readOnly();

        $row2Cols = $priceField === 'unit_price' ? 4 : 3;

        return Forms\Components\Repeater::make('products')
            ->label(__('laravel-crm-filament::labels.money.line_items'))
            ->schema([
                Forms\Components\Hidden::make($fkColumn),
                Forms\Components\Select::make('id')
                    ->label(__('laravel-crm-filament::labels.money.product'))
                    ->options(fn () => Product::query()->where('active', true)->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->createOptionForm(self::productCreateForm())
                    ->createOptionModalHeading(__('laravel-crm-filament::labels.actions.create_product'))
                    ->createOptionUsing(fn (array $data) => self::createProduct($data))
                    ->live()
                    ->afterStateUpdated(function ($state, $get, $set) use ($priceField) {
                        $product = Product::find($state);
                        if ($product && method_exists($product, 'getDefaultPrice')) {
                            $price = $product->getDefaultPrice();
                            $set($priceField, $price ? $price->unit_price / 100 : 0);
                        }
                        self::recalcRow($get, $set, $priceField);
                        self::recalcFormTotals($get, $set);
                    }),
                Grid::make($row2Cols)->schema($row2Fields),
                Forms\Components\Textarea::make('comments')
                    ->rows(2)
                    ->maxLength(255),
            ])
            ->columns(1)
            ->addActionLabel(__('laravel-crm-filament::labels.actions.add_line_item'))
            ->defaultItems(0)
            ->reorderable()
            ->columnSpanFull();
    }

    /**
     * Is inline product creation allowed on line items?
     *
     * Mirrors core CRM's ModelProducts::mount() (line 171), which flips the
     * "+ create product" button on the same `dynamic_products` setting.
     *
     * Note: SettingService::get() returns the scalar value (see resolveTaxRate
     * below), so the truthiness check is on the value itself.
     */
    public static function dynamicProductsEnabled(): bool
    {
        if (! app()->bound('laravel-crm.settings')) {
            return false;
        }

        return (bool) app('laravel-crm.settings')->get('dynamic_products');
    }

    /**
     * The inline "create product" modal schema for the line-item product Select.
     *
     * Returns null when `dynamic_products` is off, which makes the create
     * option disappear entirely: Select::getCreateOptionAction() bails early
     * when hasCreateOptionActionFormSchema() is false.
     *
     * The null has to come from here rather than from a Closure handed to
     * createOptionForm() — hasCreateOptionActionFormSchema() is a plain
     * `(bool) $this->createOptionActionForm`, so any Closure (even one that
     * evaluates to null) leaves the create button rendered with an empty modal.
     *
     * Fields mirror the subset of ProductResource::form() that core's
     * ProductService::create() actually consumes.
     *
     * @return array<int, Field>|null
     */
    public static function productCreateForm(): ?array
    {
        if (! self::dynamicProductsEnabled()) {
            return null;
        }

        return [
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(255),
            Forms\Components\TextInput::make('code')
                ->label(__('laravel-crm-filament::labels.money.sku'))
                ->maxLength(100),
            Forms\Components\TextInput::make('unit_price')
                ->label(__('laravel-crm-filament::labels.money.unit_price'))
                ->numeric(),
            Forms\Components\TextInput::make('currency')
                ->maxLength(3)
                ->default(fn () => self::defaultCurrency()),
            Forms\Components\Select::make('tax_rate_id')
                ->label(__('laravel-crm-filament::labels.money.tax_rate'))
                ->options(fn () => TaxRate::query()->orderBy('name')->pluck('name', 'id'))
                ->default(fn () => TaxRate::where('default', 1)->first()?->id),
            Forms\Components\Textarea::make('description')
                ->rows(2),
        ];
    }

    /**
     * Persist a product created from the line-item Select's inline modal and
     * hand its key back so Filament selects it on the row.
     *
     * Routed through core's ProductService (exactly as the CreateProduct page
     * does) so the product_prices row, the product_category_id mapping and the
     * Xero push stay identical to a full product create. Owner and currency
     * default the way core's ProductCreate component defaults them.
     *
     * @param  array<string, mixed>  $data
     */
    public static function createProduct(array $data): int | string | null
    {
        $data['user_owner_id'] ??= Auth::id();
        $data['currency'] ??= self::defaultCurrency();

        $product = app(ProductService::class)->create(FormPayload::wrap($data));

        return $product->getKey();
    }

    /**
     * The currency a new product's default price is written in.
     *
     * Must agree with Product::getDefaultPrice(), which filters
     * product_prices on the `currency` setting (falling back to USD) —
     * otherwise the newly created product has no "default" price and the
     * line item's unit_price would stay at 0.
     */
    private static function defaultCurrency(): string
    {
        $currency = app()->bound('laravel-crm.settings')
            ? app('laravel-crm.settings')->get('currency')
            : null;

        return is_string($currency) && $currency !== '' ? $currency : 'USD';
    }

    /**
     * Recompute a single row's `amount` (and `tax_amount` when applicable).
     *
     * Mirrors the per-row math in core CRM's LiveQuoteItems::calculateAmounts()
     * (lines 148-150): `amount = unit_price * quantity` — the line subtotal
     * does NOT include tax. When the price field is `unit_price` (Quote /
     * Order / Invoice / PurchaseOrder), `tax_amount = amount * rate / 100`
     * using the resolved rate from resolveTaxRate(). Deal uses `price` and
     * does not carry a tax_amount column, so the tax branch is skipped.
     *
     * Does NOT trigger form-level totals recalculation — that lives on a
     * subsequent story.
     */
    private static function recalcRow($get, $set, string $priceField): void
    {
        $unitPrice = (float) $get($priceField);
        $quantity = (float) $get('quantity');

        $amount = $unitPrice * $quantity;
        $set('amount', $amount);

        if ($priceField === 'unit_price') {
            $productId = $get('id');
            $rate = self::resolveTaxRate($productId !== null ? (int) $productId : null);
            $set('tax_amount', $amount * ($rate / 100));
        }
    }

    /**
     * Aggregate sibling rows up to the form-level `sub_total`, `tax`, and
     * `total` fields. Designed to be called from inside a row field's
     * reactive `afterStateUpdated` closure, where Filament's relative
     * paths resolve as follows:
     *   ../../        → the rows array keyed by row UUID
     *   ../../../X    → the form-level field X (sub_total / tax / total)
     *
     * Mirrors core CRM's LiveQuoteItems::calculateAmounts (lines 155-161)
     * exactly: sums each row's `amount` into sub_total, each row's
     * `tax_amount` into tax, and writes total = sub_total + tax.
     * Discount and adjustment are NOT included — those are applied
     * server-side at save by the various *Service::create/update methods.
     */
    private static function recalcFormTotals($get, $set): void
    {
        $rows = $get('../../') ?? [];

        $subTotal = 0.0;
        $tax = 0.0;

        foreach ($rows as $row) {
            $subTotal += (float) ($row['amount'] ?? 0);
            $tax += (float) ($row['tax_amount'] ?? 0);
        }

        $set('../../../sub_total', $subTotal);
        $set('../../../tax', $tax);
        $set('../../../total', $subTotal + $tax);
    }

    /**
     * Resolve the tax rate to apply to a line item, mirroring the fallback
     * chain in core CRM's LiveQuoteItems::calculateAmounts() (lines 133-143):
     *   1. $product->taxRate?->rate   (TaxRate relation on Product)
     *   2. $product->tax_rate          (legacy scalar column on Product)
     *   3. TaxRate::where('default', 1)->first()?->rate
     *   4. app('laravel-crm.settings')->get('tax_rate')
     *   5. 0.0
     *
     * Note: SettingService::get() resolves through Arr::get() on a
     * name => value array, so it returns the scalar value — NOT a Setting
     * model. Reading ->value off it would yield null (0.0 tax on every line).
     */
    private static function resolveTaxRate(?int $productId): float
    {
        if ($productId !== null) {
            $product = Product::find($productId);

            if ($product && $product->taxRate) {
                return (float) $product->taxRate->rate;
            }

            if ($product && $product->tax_rate) {
                return (float) $product->tax_rate;
            }
        }

        if ($default = TaxRate::where('default', 1)->first()) {
            return (float) $default->rate;
        }

        if ($setting = app('laravel-crm.settings')->get('tax_rate')) {
            return (float) $setting;
        }

        return 0.0;
    }
}
