<?php

namespace App\Services\Warehouse;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\ApiException;
use App\Http\Resources\SaleOrder;
use App\Models\Order;
use App\Models\User;
use App\Services\Sales\OrderUnits;
use App\Services\Sales\Stock;
use App\Support\Dates;
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
            ->with(['items', 'currentPayment'])
            ->whereIn('status', [OrderStatus::Paid->value, OrderStatus::Ready->value])
            ->orderByRaw('promised_dispatch_at asc nulls last')
            ->orderBy('paid_at')
            ->orderBy('order_number')
            ->get();

        $components = OrderUnits::components($orders->flatMap->items);

        return $orders->map(fn (Order $order) => [
            'order_id' => $order->getKey(),
            'number' => $order->order_number,
            'customer_name' => $order->customer_name,
            'items_label' => SaleOrder::itemsLabel($order),
            'lines' => array_values(array_map(
                fn (array $unit) => collect($unit)->only(['item_code', 'name', 'quantity'])->all(),
                OrderUnits::of($order, $components),
            )),
            'delivery_type' => $order->delivery_type,
            'status' => $order->status->value,
            'is_late' => self::isLate($order),
            'customer_phone' => $order->customer_phone,
            'shipping_address' => $order->shipping_address,
            'paid_at' => Dates::toApi($order->paid_at),
            'promised_at' => Dates::toApi($order->promised_dispatch_at),
        ])->values()->all();
    }

    /**
     * Pasó la hora acordada. Un QR que Jime confirmó después de esa hora no es
     * atraso de almacén; el efectivo se cobra al registrar la venta, así que
     * almacén siempre pudo prepararlo.
     */
    private static function isLate(Order $order): bool
    {
        $promised = $order->promised_dispatch_at;

        if ($promised === null || ! $promised->isPast()) {
            return false;
        }

        return $order->currentPayment?->method === PaymentMethod::Cash
            || ($order->paid_at !== null && $order->paid_at->lte($promised));
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
            $units = OrderUnits::of($order);
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
}
