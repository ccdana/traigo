<?php

namespace App\Services\Warehouse;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** El kardex de un producto: cada entrada y salida, de lo más nuevo a lo más antiguo. */
class StockCard
{
    public const PER_PAGE = 20;

    /** @return LengthAwarePaginator<int, object> */
    public function of(string $productId): LengthAwarePaginator
    {
        return DB::table('stock_movements as m')
            ->leftJoin('users as u', 'u.id_user', '=', 'm.created_by')
            ->leftJoin('orders as o', 'o.id_order', '=', 'm.order_id')
            ->where('m.product_id', $productId)
            ->orderByDesc('m.id_stock_movement')
            ->select([
                'm.type', 'm.quantity', 'm.source', 'm.note', 'm.created_at',
                'u.name as user_name', 'o.order_number',
            ])
            ->paginate(self::PER_PAGE);
    }
}
