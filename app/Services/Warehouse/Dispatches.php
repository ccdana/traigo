<?php

namespace App\Services\Warehouse;

use App\Enums\OrderStatus;
use App\Exceptions\ApiException;
use App\Http\Resources\SaleOrder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Sales\Stock;
use App\Support\Dates;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lo pagado que almacén prepara y entrega, de todos los canales. Al entregar,
 * lo apartado sale de la bodega y queda en el kardex.
 */
class Dispatches
{
    /**
     * Por preparar y por entregar: primero la hora acordada más cercana y, al
     * final, los que no tienen hora (por cuándo se pagaron).
     *
     * @return list<array<string, mixed>>
     */
    public static function queue(): array
    {
        $orders = Order::query()
            ->with('items')
            ->whereIn('status', [OrderStatus::Paid->value, OrderStatus::Ready->value])
            ->orderByRaw('promised_dispatch_at asc nulls last')
            ->orderBy('paid_at')
            ->orderBy('order_number')
            ->get();

        $components = self::components($orders->flatMap->items);

        return $orders->map(fn (Order $order) => [
            'order_id' => $order->getKey(),
            'number' => $order->order_number,
            'customer_name' => $order->customer_name,
            'items_label' => SaleOrder::itemsLabel($order),
            'lines' => array_values(array_map(
                fn (array $unit) => collect($unit)->only(['item_code', 'name', 'quantity'])->all(),
                self::units($order, $components),
            )),
            'delivery_type' => $order->delivery_type,
            'status' => $order->status->value,
            'is_late' => $order->promised_dispatch_at?->isPast() ?? false,
            'customer_phone' => $order->customer_phone,
            'shipping_address' => $order->shipping_address,
            'paid_at' => Dates::toApi($order->paid_at),
            'promised_at' => Dates::toApi($order->promised_dispatch_at),
        ])->values()->all();
    }

    public function ready(User $warehouse, string $orderId): void
    {
        DB::transaction(function () use ($warehouse, $orderId) {
            $order = $this->lock($orderId, OrderStatus::Paid);

            $order->update(['status' => OrderStatus::Ready, 'ready_at' => now()]);
            $order->recordStatus(OrderStatus::Paid, $warehouse);
        });
    }

    public function deliver(User $warehouse, string $orderId): void
    {
        DB::transaction(function () use ($warehouse, $orderId) {
            $order = $this->lock($orderId, OrderStatus::Ready);
            $units = self::units($order, self::components($order->items));
            $inventory = Stock::lock(array_keys($units));

            foreach ($units as $productId => $unit) {
                // Cada pedido aparta lo suyo al registrarse: si no alcanza,
                // algo no cuadra y no se descuenta lo de otros pedidos.
                if (($inventory->get($productId)?->reserved ?? 0) < $unit['quantity']) {
                    throw ApiException::conflict(
                        'insufficient_stock',
                        "El stock apartado de {$unit['name']} no alcanza para entregar este pedido.",
                        ['product_id' => $productId],
                    );
                }

                Stock::ship($productId, $unit['quantity']);
                DB::table('stock_movements')->insert([
                    'product_id' => $productId,
                    'type' => 'sale',
                    'quantity' => -$unit['quantity'],
                    'order_id' => $order->getKey(),
                    'source' => 'app',
                    'created_by' => $warehouse->getKey(),
                ]);
            }

            $order->update([
                'status' => OrderStatus::Delivered,
                'dispatched_at' => now(),
                'delivered_at' => now(),
            ]);
            $order->recordStatus(OrderStatus::Ready, $warehouse);
        });
    }

    private function lock(string $orderId, OrderStatus $expected): Order
    {
        $order = Order::query()->lockForUpdate()->find($orderId)
            ?? throw ApiException::notFound('No encontramos ese pedido.');

        if ($order->status !== $expected) {
            throw ApiException::conflict('invalid_status', 'El pedido cambió de estado. Revisa la lista.');
        }

        return $order;
    }

    /**
     * Lo que hay que sacar de la bodega, por producto. Los combos no tienen
     * stock propio: salen sus componentes.
     *
     * @param  Collection<string, Collection<int, object>>  $components  por combo
     * @return array<string, array{item_code: string, name: string, quantity: int}>
     */
    private static function units(Order $order, Collection $components): array
    {
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
    private static function components(Collection $items): Collection
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
