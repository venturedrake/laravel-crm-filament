<?php

namespace VentureDrake\LaravelCrmFilament\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\ProductPrice;
use VentureDrake\LaravelCrm\Models\ProductVariation;
use VentureDrake\LaravelCrmFilament\Support\CrmMoney;

/**
 * The product show page's Variations card (core 2.5.0).
 *
 * Read-only: variations are edited on the product form, which hands the whole
 * set to ProductService::syncVariations() so the row order, the soft deletes
 * and the single price per row stay core's.
 *
 * Each price is the variation's own in the product price's currency. A
 * variation without one sells at the product price, which is shown instead.
 */
class ProductVariationsRelationManager extends RelationManager
{
    protected static string $relationship = 'productVariations';

    protected static ?string $title = 'Variations';

    private ?ProductPrice $productPrice = null;

    private bool $productPriceLoaded = false;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('laravel-crm-filament::labels.sections.variations');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('productPrices'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('laravel-crm-filament::labels.fields.name')),
                Tables\Columns\TextColumn::make('code')
                    ->label(__('laravel-crm-filament::labels.money.sku')),
                Tables\Columns\TextColumn::make('barcode')
                    ->label(__('laravel-crm-filament::labels.money.barcode')),
                CrmMoney::column('unit_price', fn ($record) => $this->priceRow($record)?->currency)
                    ->label(__('laravel-crm-filament::labels.money.unit_price'))
                    ->state(fn ($record) => $this->priceRow($record)?->unit_price)
                    ->color(fn ($record) => $this->ownPrice($record) ? null : 'gray')
                    ->placeholder('—'),
                Tables\Columns\IconColumn::make('active')
                    ->label(__('laravel-crm-filament::labels.fields.active'))
                    ->boolean(),
            ])
            ->emptyStateHeading(__('laravel-crm-filament::labels.money.no_variations'))
            ->emptyStateDescription(null)
            ->paginated(false)
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }

    protected function ownPrice(ProductVariation $variation): ?ProductPrice
    {
        return $variation->priceFor($this->productPrice()?->currency);
    }

    protected function priceRow(ProductVariation $variation): ?ProductPrice
    {
        return $this->ownPrice($variation) ?? $this->productPrice();
    }

    /**
     * The product's own price row, read once per request rather than per cell.
     */
    protected function productPrice(): ?ProductPrice
    {
        if (! $this->productPriceLoaded) {
            /** @var Product $product */
            $product = $this->getOwnerRecord();

            $this->productPrice = $product->getPrimaryPrice();
            $this->productPriceLoaded = true;
        }

        return $this->productPrice;
    }
}
