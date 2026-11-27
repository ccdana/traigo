<?php

namespace App\Services\Store;

/**
 * Lo que la tienda muestra de un producto. No lleva costo ni comisión: así no
 * pueden salir por error en una página pública.
 */
final readonly class ProductCard
{
    public function __construct(
        public string $id,
        public string $slug,
        public string $itemCode,
        public string $name,
        public string $categoryName,
        public ?string $categorySlug,
        public int $priceCents,
        public ?int $listCents,
        public ?int $wholesaleCents,
        public ?int $wholesaleMinQty,
        public int $available,
        public ?string $imageUrl,
    ) {}

    public function isAvailable(): bool
    {
        return $this->available > 0;
    }
}
