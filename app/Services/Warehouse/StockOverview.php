<?php

namespace App\Services\Warehouse;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** El stock de todos los productos, como se ve en el panel de inventario. */
class StockOverview
{
    public const PER_PAGE = 30;

    public const STATUSES = [
        'out' => 'Agotado',
        'low' => 'Stock bajo',
        'ok' => 'Disponible',
    ];

    private const AVAILABLE = '(coalesce(inventory.stock, 0) - coalesce(inventory.reserved, 0))';

    public static function status(int $available): string
    {
        return match (true) {
            $available <= 0 => 'out',
            $available <= config('traigo.low_stock_threshold') => 'low',
            default => 'ok',
        };
    }

    /**
     * También los que ya no se venden: siguen ocupando la bodega.
     *
     * @return LengthAwarePaginator<int, Product>
     */
    public function search(?string $search, ?string $categoryId, ?string $status): LengthAwarePaginator
    {
        $low = (int) config('traigo.low_stock_threshold');

        return Product::query()
            ->forCatalog()
            ->when(filled($search), fn (Builder $query) => $query->matching($search))
            ->when($categoryId !== null, fn (Builder $query) => $query->where('products.category_id', $categoryId))
            ->when($status === 'out', fn (Builder $query) => $query->whereRaw(self::AVAILABLE.' <= 0'))
            ->when($status === 'low', fn (Builder $query) => $query->whereRaw(self::AVAILABLE.' between 1 and ?', [$low]))
            ->when($status === 'ok', fn (Builder $query) => $query->whereRaw(self::AVAILABLE.' > ?', [$low]))
            ->orderByRaw(Product::foldSql('products.name'))
            ->orderBy('products.item_code')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /** @return object{products: int, stock: int, reserved: int, available: int, out_of_stock: int, low_stock: int} */
    public function summary(): object
    {
        $available = self::AVAILABLE;

        return DB::table('products')
            ->leftJoin('inventory', 'inventory.product_id', '=', 'products.id_product')
            ->selectRaw(
                "count(*)::int as products,
                coalesce(sum(inventory.stock), 0)::int as stock,
                coalesce(sum(inventory.reserved), 0)::int as reserved,
                coalesce(sum({$available}), 0)::int as available,
                (count(*) filter (where {$available} <= 0))::int as out_of_stock,
                (count(*) filter (where {$available} between 1 and ?))::int as low_stock",
                [(int) config('traigo.low_stock_threshold')],
            )
            ->first();
    }
}
