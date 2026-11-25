<?php

namespace App\Services\Sales;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Lo apartado por pedidos. Siempre dentro de una transacción. */
final class Stock
{
    /**
     * Bloquea el inventario de esos productos hasta que termine la
     * transacción. Siempre en el mismo orden, para que dos ventas a la vez no
     * se traben entre sí.
     *
     * @param  list<string>  $productIds
     * @return Collection<string, object{stock: int, reserved: int}> por producto
     */
    public static function lock(array $productIds): Collection
    {
        sort($productIds);

        return DB::table('inventory')
            ->whereIn('product_id', $productIds)
            ->orderBy('product_id')
            ->lockForUpdate()
            ->get(['product_id', 'stock', 'reserved'])
            ->keyBy('product_id');
    }

    /** Aparta (cantidad positiva) o libera (negativa) unidades. */
    public static function reserve(string $productId, int $quantity): void
    {
        DB::table('inventory')->where('product_id', $productId)->update([
            'reserved' => DB::raw('reserved + '.(int) $quantity),
            'updated_at' => now(),
        ]);
    }
}
