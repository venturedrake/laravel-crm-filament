<?php

namespace VentureDrake\LaravelCrmFilament\Concerns\Imports;

use Ramsey\Uuid\Uuid;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\ProductCategory;
use VentureDrake\LaravelCrm\Services\ProductService;
use VentureDrake\LaravelCrmFilament\Support\FormPayload;

class ProductImporter extends Importer
{
    public function permission(): ?string
    {
        return 'create crm products';
    }

    public function columns(): array
    {
        return [
            'name' => 'Name',
            'code' => 'Code',
            'barcode' => 'Barcode',
            'unit_price' => 'Unit price',
            'currency' => 'Currency',
            'product_category' => 'Category',
        ];
    }

    public function dedupeField(): string
    {
        return 'code';
    }

    public function sampleRow(): array
    {
        return [
            'name' => 'Widget',
            'code' => 'WID-001',
            'barcode' => '0123456789012',
            'unit_price' => '19.99',
            'currency' => config('laravel-crm.default_currency', 'USD'),
            'product_category' => 'Hardware',
        ];
    }

    public function importRow(array $row): bool
    {
        $name = trim((string) ($row['name'] ?? ''));
        $code = trim((string) ($row['code'] ?? ''));

        if ($name === '') {
            return false;
        }

        if ($code !== '' && Product::where('code', $code)->exists()) {
            return false;
        }

        $categoryId = null;
        $categoryName = trim((string) ($row['product_category'] ?? ''));
        if ($categoryName !== '') {
            $categoryId = $this->findOrCreateCategory($categoryName);
        }

        $unitPrice = isset($row['unit_price']) && $row['unit_price'] !== ''
            ? (float) $row['unit_price']
            : null;

        $currency = trim((string) ($row['currency'] ?? '')) ?: config('laravel-crm.default_currency', 'USD');

        $payload = FormPayload::wrap([
            'name' => $name,
            'code' => $code !== '' ? $code : null,
            'barcode' => $row['barcode'] ?? null,
            'purchase_account' => null,
            'sales_account' => null,
            'product_category' => $categoryId,
            'unit' => null,
            'tax_rate_id' => null,
            'tax_rate' => null,
            'description' => null,
            'user_owner_id' => null,
            'unit_price' => $unitPrice,
            'currency' => $currency,
        ]);

        app(ProductService::class)->create($payload);

        return true;
    }

    protected function findOrCreateCategory(string $name): int
    {
        if ($existing = ProductCategory::where('name', $name)->first()) {
            return $existing->id;
        }

        return ProductCategory::create([
            'external_id' => Uuid::uuid4()->toString(),
            'name' => $name,
        ])->id;
    }
}
