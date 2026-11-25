<?php

namespace App\Services\Sales;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cancelar antes de cobrar: libera lo apartado, anula las comisiones y rechaza
 * el comprobante que esperaba (así su referencia queda libre).
 */
class CancelSale
{
    public function cancel(User $seller, string $orderId, ?string $reason): Order
    {
        return DB::transaction(function () use ($seller, $orderId, $reason): Order {
            $order = OwnOrders::lock($seller, $orderId);

            if (! $order->status->isAwaitingPayment()) {
                throw ApiException::conflict('not_cancellable', 'Este pedido ya no se puede cancelar.');
            }

            $items = $order->items()->get(['product_id', 'quantity']);
            Stock::lock($items->pluck('product_id')->all());
            foreach ($items as $item) {
                Stock::reserve($item->product_id, -$item->quantity);
            }

            $order->commissions()->where('status', 'pending')->update([
                'status' => 'cancelled',
                'updated_at' => now(),
            ]);
            $order->payments()->where('status', PaymentStatus::Submitted->value)->get()
                ->each->update(['status' => PaymentStatus::Rejected, 'reject_reason' => 'Pedido cancelado']);

            $from = $order->status;
            $order->update([
                'status' => OrderStatus::Cancelled,
                'cancelled_at' => now(),
                'cancel_reason' => filled($reason) ? trim($reason) : null,
            ]);
            $order->recordStatus($from, $seller, $order->cancel_reason);

            return $order;
        });
    }
}
