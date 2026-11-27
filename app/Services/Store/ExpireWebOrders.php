<?php

namespace App\Services\Store;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Services\Sales\OrderUnits;
use App\Services\Sales\Stock;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Vence los pedidos web que no se pagaron a tiempo y devuelve lo apartado a la
 * tienda. Un pedido con comprobante en revisión no vence: espera a Jime. Las
 * ventas de la app no se tocan.
 */
final class ExpireWebOrders
{
    private const REASON = 'Reserva vencida sin pago';

    /** @return int cuántos pedidos vencieron */
    public function run(?string $orderId = null): int
    {
        $due = Order::query()
            ->where('origin', 'web')
            ->where('status', OrderStatus::PendingPayment->value)
            ->where('expires_at', '<=', now())
            ->when($orderId !== null, fn ($query) => $query->whereKey($orderId))
            ->pluck('id_order');

        $expired = 0;

        foreach ($due as $id) {
            try {
                $expired += $this->expire($id) ? 1 : 0;
            } catch (Throwable $e) {
                // Un pedido con datos descuadrados no detiene a los demás.
                report($e);
            }
        }

        return $expired;
    }

    private function expire(string $orderId): bool
    {
        return DB::transaction(function () use ($orderId): bool {
            // Lo hace el sistema, aunque se dispare desde la visita de alguien.
            DB::select("select set_config('traigo.user_id', '', true)");

            $order = Order::query()->lockForUpdate()->find($orderId);

            // Mientras esperábamos el turno pudo subir su comprobante.
            if (! $order || $order->status !== OrderStatus::PendingPayment || $order->expires_at?->isFuture() !== false) {
                return false;
            }

            $units = OrderUnits::of($order);
            Stock::lock(array_keys($units));

            foreach ($units as $productId => $unit) {
                Stock::reserve($productId, -$unit['quantity']);
            }

            $order->payments()
                ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Submitted->value])
                ->get()
                ->each->update(['status' => PaymentStatus::Rejected, 'reject_reason' => self::REASON]);

            $order->update(['status' => OrderStatus::Expired]);
            $order->recordStatus(OrderStatus::PendingPayment, null, self::REASON);

            return true;
        });
    }
}
