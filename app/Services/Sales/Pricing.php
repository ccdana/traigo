<?php

namespace App\Services\Sales;

use App\Models\Product;
use App\Support\Money;

/**
 * Regla de precio (igual que en la app, `pricing.dart`): un producto va a
 * precio por mayor cuando se piden al menos sus unidades mínimas del mismo
 * código; si no, va por unidad. No hay un monto mínimo de pedido. Las
 * promociones son de la tienda web: no se aplican a las ventas de la app.
 */
final class Pricing
{
    /** @return array{type: 'unit'|'wholesale', cents: int} */
    public static function unitPrice(Product $product, int $quantity): array
    {
        $wholesale = $product->price_wholesale_bs !== null
            && $product->wholesale_min_qty !== null
            && $quantity >= $product->wholesale_min_qty;

        return $wholesale
            ? ['type' => 'wholesale', 'cents' => Money::toCents($product->price_wholesale_bs)]
            : ['type' => 'unit', 'cents' => Money::toCents($product->price_unit_bs)];
    }
}
