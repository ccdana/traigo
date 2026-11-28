<?php

namespace App\Services\Store;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Collection;

/**
 * El carrito del cliente, en su sesión. Solo guarda producto y cantidad: los
 * precios y el stock se leen de la base cada vez. No aparta stock; eso pasa al
 * confirmar el pedido.
 */
final class Bag
{
    private const KEY = 'store.bag';

    public const MAX_LINES = 50;

    public const MAX_QUANTITY = 999;

    public function __construct(
        private readonly Session $session,
        private readonly StoreCatalog $catalog,
    ) {}

    /** @return array<string, int> cantidad por producto */
    public function items(): array
    {
        return $this->session->get(self::KEY, []);
    }

    /** Cuántos productos distintos hay (lo que muestra el encabezado). */
    public function count(): int
    {
        return count($this->items());
    }

    public function quantityOf(string $productId): int
    {
        return $this->items()[$productId] ?? 0;
    }

    public function isFull(string $productId): bool
    {
        return $this->quantityOf($productId) === 0 && $this->count() >= self::MAX_LINES;
    }

    /** Cantidad cero o menos: se quita. */
    public function put(string $productId, int $quantity): void
    {
        $items = $this->items();

        if ($quantity <= 0) {
            unset($items[$productId]);
        } else {
            $items[$productId] = min($quantity, self::MAX_QUANTITY);
        }

        $this->session->put(self::KEY, $items);
    }

    public function clear(): void
    {
        $this->session->forget(self::KEY);
    }

    public function remove(string $productId): void
    {
        $this->put($productId, 0);
    }

    /**
     * Lo que hay en el carrito con los precios de hoy. Lo que dejó de venderse
     * se quita solo.
     *
     * @return array{lines: Collection<int, array<string, mixed>>, units: int, subtotal_cents: int, removed: int, has_shortage: bool}
     */
    public function contents(): array
    {
        $items = $this->items();
        $products = $this->catalog->productsById(array_keys($items));
        $removed = 0;

        foreach (array_keys($items) as $productId) {
            if (! $products->has($productId)) {
                $this->remove($productId);
                unset($items[$productId]);
                $removed++;
            }
        }

        $promotions = StorePricing::activePromotions(array_keys($items));

        $lines = collect($items)->map(function (int $quantity, string $productId) use ($products, $promotions) {
            $product = $products->get($productId);
            $active = $promotions->get($productId, collect());
            $card = $this->catalog->card($product, $active);
            $price = StorePricing::price($product, $quantity, $active);
            $missing = $card->wholesaleMinQty !== null && $quantity < $card->wholesaleMinQty
                ? $card->wholesaleMinQty - $quantity
                : null;

            return [
                'product' => $card,
                'quantity' => $quantity,
                'price_type' => $price['type'],
                'unit_cents' => $price['cents'],
                'total_cents' => $price['cents'] * $quantity,
                'missing_for_wholesale' => $missing,
                'exceeds_stock' => $quantity > $card->available,
            ];
        })->values();

        return [
            'lines' => $lines,
            'units' => (int) $lines->sum('quantity'),
            'subtotal_cents' => (int) $lines->sum('total_cents'),
            'removed' => $removed,
            'has_shortage' => $lines->contains('exceeds_stock', true),
        ];
    }
}
