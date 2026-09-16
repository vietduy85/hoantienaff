<?php

namespace App\Services\PriceComparison\DataTransfer;

/**
 * Normalized product data shared across catalog providers.
 * Prices are kept as-is from the source API (whole VND, no division).
 */
final class ProductSummary
{
    public function __construct(
        public readonly string $source,
        public readonly string $sku,
        public readonly ?string $skuId = null,
        public readonly ?string $name = null,
        public readonly ?string $barcode = null,
        public readonly ?string $brand = null,
        public readonly ?string $category = null,
        public readonly int|float|null $price = null,
        public readonly int|float|null $originalPrice = null,
        public readonly int|float|null $supplierRetailPrice = null,
        public readonly int|float|null $discountAmount = null,
        public readonly int|float|null $discountPercent = null,
        public readonly ?string $imageUrl = null,
        public readonly ?string $productUrl = null,
        public readonly ?int $stock = null,
        public readonly bool $sellable = false,
        public readonly ?string $unit = null,
        public readonly ?string $slug = null,
        public readonly ?string $seller = null,
        public readonly ?string $manufacturer = null,
        public readonly ?array $categories = null,
        public readonly ?array $rawData = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source'           => $this->source,
            'sku'              => $this->sku,
            'sku_id'           => $this->skuId,
            'name'             => $this->name,
            'barcode'          => $this->barcode,
            'brand'            => $this->brand,
            'category'         => $this->category,
            'price'            => $this->price,
            'original_price'   => $this->originalPrice,
            'supplier_retail_price' => $this->supplierRetailPrice,
            'discount_amount'  => $this->discountAmount,
            'discount_percent' => $this->discountPercent,
            'image_url'        => $this->imageUrl,
            'product_url'      => $this->productUrl,
            'stock'            => $this->stock,
            'sellable'         => $this->sellable,
            'unit'             => $this->unit,
            'slug'             => $this->slug,
            'seller'           => $this->seller,
        ];
    }
}