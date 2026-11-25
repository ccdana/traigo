<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CatalogSeeder extends Seeder
{
    /**
     * Los 62 productos de la lista maestra del 23/11/2026. El stock empieza en 0
     * hasta que se cargue el inventario verificado; la comisión y el costo quedan
     * por definir. (A382218, el dino en huevo, no llegó y no se incluye.)
     */
    public function run(): void
    {
        DB::transaction(function () {
            $categories = [];
            foreach (['Tomatodos' => 'tomatodos', 'Macetas' => 'macetas', 'Juguetes' => 'juguetes'] as $name => $slug) {
                $categories[$name] = DB::table('categories')->insertGetId(
                    ['name' => $name, 'slug' => $slug],
                    'id_category',
                );
            }

            foreach (require __DIR__.'/data/catalog.php' as $product) {
                $productId = DB::table('products')->insertGetId([
                    'item_code' => $product['item_code'],
                    'category_id' => $categories[$product['category']],
                    'name' => $product['name'],
                    'slug' => $product['slug'],
                    'price_unit_bs' => $product['price_unit_bs'],
                    'price_wholesale_bs' => $product['price_wholesale_bs'],
                    'wholesale_min_qty' => $product['wholesale_min_qty'],
                ], 'id_product');

                DB::table('inventory')->insert(['product_id' => $productId]);
            }
        });
    }
}
