<?php

namespace App\Services\Control;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Http\Resources\SaleOrder;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\Sales\OrderUnits;
use App\Services\Sales\Stock;
use App\Services\Team\WeekLock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Todos los pedidos, como los ve Jime para registrar devoluciones y quitar los
 * registrados dos veces.
 */
class ControlOrders
{
    public const PER_PAGE = 20;

    /** Del más nuevo al más antiguo; busca por número o nombre del cliente. */
    public static function search(?string $search): LengthAwarePaginator
    {
        $query = Order::query()->with(['items', 'currentPayment', 'seller']);

        if (filled($search)) {
            $term = addcslashes(Product::fold($search), '%_\\');
            $query->whereRaw(Product::foldSql("orders.order_number || ' ' || orders.customer_name").' like ?', ["%{$term}%"]);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id_order')->paginate(self::PER_PAGE);
    }

    /** @return array<string, mixed> */
    public static function summary(Order $order): array
    {
        return [...SaleOrder::summary($order), 'seller_name' => $order->seller?->name];
    }

    /** @return array<string, mixed> */
    public static function view(string $orderId): array
    {
        $order = Order::query()->with([...SaleOrder::DETAIL_RELATIONS, 'payments', 'seller'])->find($orderId)
            ?? throw self::missing();

        return [
            ...SaleOrder::detail($order),
            // La comisión es de quien vende.
            'commission' => null,
            'can_cancel' => false,
            'can_upload_proof' => false,
            'seller_name' => $order->seller?->name,
            'can_return' => self::canReturn($order),
            'can_mark_duplicate' => self::canMarkDuplicate($order),
            'void_reason' => $order->status === OrderStatus::Returned
                ? DB::table('order_status_history')
                    ->where('order_id', $orderId)
                    ->where('to_status', OrderStatus::Returned->value)
                    ->orderByDesc('id_order_status_history')
                    ->value('note')
                : null,
            'duplicate_of_number' => $order->duplicate_of_id === null
                ? null
                : Order::query()->whereKey($order->duplicate_of_id)->value('order_number'),
        ];
    }

    /**
     * Devolución: se revierte lo cobrado (deja de contar para el bono), se
     * descuenta la comisión ganada y la mercadería vuelve al stock (o se suelta
     * lo apartado, si no se había entregado).
     */
    public function returnOrder(User $jime, string $orderId, string $reason): void
    {
        DB::transaction(function () use ($jime, $orderId, $reason) {
            $order = $this->lock($orderId);
            if ($order->seller_id === $jime->getKey()) {
                throw ApiException::conflict('own_record', 'Nadie valida la devolución de un pedido propio.');
            }

            if (! self::canReturn($order)) {
                throw ApiException::conflict('invalid_status', 'Este pedido no se puede devolver.');
            }

            $delivered = $order->status === OrderStatus::Delivered;
            $units = OrderUnits::of($order);

            if ($delivered) {
                Stock::lock(array_keys($units));
                foreach ($units as $productId => $unit) {
                    DB::table('inventory')->where('product_id', $productId)->update([
                        'stock' => DB::raw('stock + '.(int) $unit['quantity']),
                        'updated_at' => now(),
                    ]);
                    DB::table('stock_movements')->insert([
                        'product_id' => $productId,
                        'type' => 'sale_return',
                        'quantity' => $unit['quantity'],
                        'order_id' => $order->getKey(),
                        'source' => 'app',
                        'created_by' => $jime->getKey(),
                        'note' => $reason,
                    ]);
                }
            } else {
                $this->release($units);
            }

            $this->closePayments($jime, $order, $reason);
            Commissions::reverse($order->getKey(), $jime, $reason);

            $from = $order->status;
            $order->update(['status' => OrderStatus::Returned]);
            $order->recordStatus($from, $jime, $reason);
        });
    }

    /** El mismo pedido registrado dos veces: se anula este y queda ligado al original. */
    public function markDuplicate(User $jime, string $orderId, string $originalId): void
    {
        DB::transaction(function () use ($jime, $orderId, $originalId) {
            // Siempre en el mismo orden, para que dos anulaciones no se traben.
            Order::query()->whereKey([$orderId, $originalId])->orderBy('id_order')->lockForUpdate()->get();
            $order = $this->lock($orderId);
            if ($order->seller_id === $jime->getKey()) {
                throw ApiException::conflict('own_record', 'Nadie marca como duplicado un pedido propio.');
            }

            if (! self::canMarkDuplicate($order)) {
                throw ApiException::conflict(
                    'invalid_status',
                    'Con un cobro confirmado o ya entregado, registra una devolución.',
                );
            }

            $original = Order::query()->find($originalId);

            if (! $original || $original->getKey() === $order->getKey()
                || $original->status->isVoid() || $original->duplicate_of_id !== null) {
                throw ApiException::invalid('Elige como original un pedido vigente.');
            }

            $this->release(OrderUnits::of($order));
            $this->closePayments($jime, $order, "Duplicado de {$original->order_number}");
            Commissions::cancelPending($order->getKey());

            $from = $order->status;
            $order->update([
                'status' => OrderStatus::Cancelled,
                'cancelled_at' => now(),
                'cancel_reason' => 'duplicate',
                'duplicate_of_id' => $original->getKey(),
            ]);
            $order->recordStatus($from, $jime, "Duplicado de {$original->order_number}");
        });
    }

    private static function canReturn(Order $order): bool
    {
        return in_array($order->status, [OrderStatus::Paid, OrderStatus::Ready, OrderStatus::Delivered], true);
    }

    /** Con un cobro confirmado o ya entregado no es un duplicado: es devolución. */
    private static function canMarkDuplicate(Order $order): bool
    {
        return in_array($order->status, [
            OrderStatus::PendingPayment, OrderStatus::PaymentReview, OrderStatus::Paid, OrderStatus::Ready,
        ], true) && ! $order->payments()->where('status', PaymentStatus::Confirmed->value)->exists();
    }

    /** @param  array<string, array{item_code: string, name: string, quantity: int}>  $units */
    private function release(array $units): void
    {
        $inventory = Stock::lock(array_keys($units));

        foreach ($units as $productId => $unit) {
            if (($inventory->get($productId)?->reserved ?? 0) < $unit['quantity']) {
                throw ApiException::conflict(
                    'insufficient_stock',
                    "El stock apartado de {$unit['name']} no cuadra con este pedido.",
                    ['product_id' => $productId],
                );
            }

            Stock::reserve($productId, -$unit['quantity']);
        }
    }

    /** Lo confirmado se revierte; lo que faltaba confirmar se rechaza. */
    private function closePayments(User $jime, Order $order, string $reason): void
    {
        $payments = Payment::query()->where('order_id', $order->getKey())->lockForUpdate()->get();

        // Si lo devuelto contaba para un bono que Dirección está autorizando,
        // la devolución espera a que termine (y la autorización la ve).
        foreach ($payments->where('status', PaymentStatus::Confirmed) as $payment) {
            $week = WeekLock::weekOfDay($payment->received_at->timezone(config('app.timezone'))->toDateString());
            if ($week !== null) {
                WeekLock::status($week, lock: true);
            }
        }

        foreach ($payments as $payment) {
            $values = match ($payment->status) {
                PaymentStatus::Confirmed => [
                    'status' => PaymentStatus::Reversed,
                    'reversed_by' => $jime->getKey(),
                    'reversed_at' => now(),
                    'reversal_reason' => $reason,
                ],
                PaymentStatus::Pending, PaymentStatus::Submitted => [
                    'status' => PaymentStatus::Rejected,
                    'reject_reason' => $reason,
                    'reviewed_by' => $jime->getKey(),
                    'reviewed_at' => now(),
                ],
                default => null,
            };

            if ($values !== null) {
                $payment->forceFill($values)->save();
            }
        }
    }

    private function lock(string $orderId): Order
    {
        return Order::query()->with('items')->lockForUpdate()->find($orderId) ?? throw self::missing();
    }

    private static function missing(): ApiException
    {
        return ApiException::notFound('No encontramos ese pedido.');
    }
}
