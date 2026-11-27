<?php

namespace App\Services\Sales;

use App\Exceptions\ApiException;
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
     * @param  Collection<int, Collection<int, object>>|null  $components  por línea, si ya se cargaron
     * @return array<string, array{item_code: string, name: string, quantity: int}> por producto
     */
    public static function of(Order $order, ?Collection $components = null, bool $requireComplete = true): array
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

            $snapshot = $components->get($item->getKey(), []);
            if ($snapshot === [] && $requireComplete) {
                throw ApiException::conflict(
                    'missing_combo_history',
                    'Hace falta verificar la composición histórica de este combo antes de mover su stock.',
                );
            }

            foreach ($snapshot as $component) {
                $add(
                    $component->product_id,
                    $component->item_code,
                    $component->name,
                    $component->quantity_per_combo * $item->quantity,
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
        $lineIds = $items->whereNotNull('combo_id')->pluck('id_order_item')->all();
        if ($lineIds === []) {
            return collect();
        }

        return DB::table('order_item_components')
            ->whereIn('order_item_id', $lineIds)
            ->orderBy('name')
            ->get()
            ->groupBy('order_item_id');
    }
}
