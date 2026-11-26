<?php

namespace App\Services\Sales;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lo que un pedido aparta o saca de la bodega, por producto. Los combos no
 * tienen stock propio: cuentan sus componentes.
 */
final class OrderUnits
{
    /**
     * @param  Collection<string, Collection<int, object>>|null  $components  por combo, si ya se cargaron
     * @return array<string, array{item_code: string, name: string, quantity: int}> por producto
     */
    public static function of(Order $order, ?Collection $components = null): array
    {
        $components ??= self::components($order->items);
        $units = [];
        $add = function (string $productId, string $code, string $name, int $quantity) use (&$units) {
            $units[$productId] ??= ['item_code' => $code, 'name' => $name, 'quantity' => 0];
            $units[$productId]['quantity'] += $quantity;
        };

        foreach ($order->items as $item) {
            if ($item->product_id !== null) {
                $add($item->product_id, $item->item_code, $item->product_name, $item->quantity);

                continue;
            }

            foreach ($components->get($item->combo_id, []) as $component) {
                $add(
                    $component->product_id,
                    $component->item_code,
                    $component->name,
                    $component->quantity * $item->quantity,
                );
            }
        }

        return $units;
    }

    /**
     * @param  Collection<int, OrderItem>  $items
     * @return Collection<string, Collection<int, object>>
     */
    public static function components(Collection $items): Collection
    {
        $combos = $items->pluck('combo_id')->filter()->unique();

        if ($combos->isEmpty()) {
            return collect();
        }

        return DB::table('combo_items as c')
            ->join('products as p', 'p.id_product', '=', 'c.product_id')
            ->whereIn('c.combo_id', $combos->values())
            ->orderBy('p.name')
            ->get(['c.combo_id', 'c.product_id', 'c.quantity', 'p.item_code', 'p.name'])
            ->groupBy('combo_id');
    }
}
