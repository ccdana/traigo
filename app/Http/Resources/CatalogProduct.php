<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/** Un producto del catálogo. El costo no viaja nunca por aquí. */
final class CatalogProduct
{
    /**
     * @param  Product  $product  cargado con `forCatalog()`
     * @return array<string, mixed>
     */
    public static function toArray(Product $product, User $viewer): array
    {
        // La comisión solo la ven quienes venden, Jime y Dirección. Va como
        // porcentaje de 0 a 100; nulo es «por definir».
        $seesCommission = $viewer->role->canSell() || $viewer->role->requiresSecondStep();

        return [
            'id' => $product->getKey(),
            'item_code' => $product->item_code,
            'name' => $product->name,
            'category' => $product->category_name ?? 'Sin categoría',
            'price_unit' => Money::fromDatabase($product->price_unit_bs),
            'price_wholesale' => Money::fromDatabase($product->price_wholesale_bs),
            'wholesale_min_qty' => $product->wholesale_min_qty,
            'stock' => (int) $product->stock,
            'reserved' => (int) $product->reserved,
            'available' => (int) $product->stock - (int) $product->reserved,
            'commission_pct' => $seesCommission ? $product->commission_pct : null,
            'image_url' => $product->image_url,
        ];
    }

    /** Además, la descripción y todas las fotos. */
    public static function detail(Product $product, User $viewer): array
    {
        return [
            ...self::toArray($product, $viewer),
            'description' => $product->description,
            'images' => DB::table('product_images')
                ->where('product_id', $product->getKey())
                ->orderBy('position')
                ->orderBy('created_at')
                ->pluck('url')
                ->all(),
        ];
    }
}
