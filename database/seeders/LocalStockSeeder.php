<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Solo para probar ventas en una PC de desarrollo: carga el mismo stock de
 * ejemplo que la demo de la app en los productos que están en 0, con su
 * movimiento «initial» en el kardex. El stock de verdad se carga desde la app
 * (almacén). Nunca corre en producción.
 *
 *   php artisan db:seed --class=LocalStockSeeder
 */
class LocalStockSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('LocalStockSeeder solo corre con APP_ENV=local o testing.');
        }

        $stock = require __DIR__.'/data/demo_stock.php';

        DB::transaction(function () use ($stock) {
            $products = DB::table('inventory')
                ->join('products', 'products.id_product', '=', 'inventory.product_id')
                ->where('inventory.stock', 0)
                ->whereIn('products.item_code', array_keys($stock))
                ->lockForUpdate()
                ->pluck('products.item_code', 'inventory.product_id');

            foreach ($products as $productId => $code) {
                $quantity = $stock[$code];

                DB::table('inventory')->where('product_id', $productId)->update([
                    'stock' => $quantity,
                    'updated_at' => now(),
                ]);
                DB::table('stock_movements')->insert([
                    'product_id' => $productId,
                    'type' => 'initial',
                    'quantity' => $quantity,
                    'source' => 'system',
                    'note' => 'Stock de ejemplo (solo desarrollo)',
                ]);
            }
        });
    }
}
