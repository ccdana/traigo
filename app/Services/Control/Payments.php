<?php

namespace App\Services\Control;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Http\Resources\SaleOrder;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Team\WeekLock;
use App\Support\Dates;
use App\Support\FileLinks;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Conciliación de comprobantes: Jime confirma que el dinero llegó a la cuenta
 * de Traigo o rechaza la foto. Al confirmar, el pedido queda «Pagado», la
 * comisión se gana y el cobro cuenta para el bono de la semana en que llegó el
 * dinero.
 */
class Payments
{
    /** @return array<string, mixed> */
    public static function view(string $paymentId): array
    {
        $payment = Payment::query()->find($paymentId) ?? throw self::missing();
        $order = Order::query()->with(['items', 'seller'])->findOrFail($payment->order_id);

        return [
            'id' => $payment->getKey(),
            'order' => [
                'id' => $order->getKey(),
                'number' => $order->order_number,
                'customer_name' => $order->customer_name,
                'seller_name' => $order->seller?->name,
                'items_label' => SaleOrder::itemsLabel($order),
                'total' => Money::fromDatabase($order->total_bs),
            ],
            'method' => $payment->method->value,
            'amount' => Money::fromDatabase($payment->amount_bs),
            'status' => $payment->status->value,
            'reference' => $payment->reference,
            'reference_unique' => ! self::referenceUsed($payment),
            'proof_url' => FileLinks::temporary($payment->proof_url),
            'submitted_at' => Dates::toApi($payment->submitted_at),
            'destination_account' => config('traigo.payment_account'),
            'reject_reason' => $payment->reject_reason,
        ];
    }

    public function confirm(
        User $jime,
        string $paymentId,
        bool $amountMatches,
        bool $destinationOk,
        ?Carbon $receivedAt,
    ): void {
        DB::transaction(function () use ($jime, $paymentId, $amountMatches, $destinationOk, $receivedAt) {
            [$order, $payment] = $this->lockForReview($paymentId);

            if (! $amountMatches || ! $destinationOk) {
                throw ApiException::invalid('Si el monto o la cuenta no coinciden, rechaza el comprobante.');
            }

            if ($payment->submitted_by === $jime->getKey()) {
                throw ApiException::conflict('own_record', 'Nadie confirma un comprobante que subió.');
            }

            if (self::referenceUsed($payment)) {
                throw ApiException::conflict('reference_duplicated', 'Ese número de referencia ya se usó en otro pago.');
            }

            $received = $receivedAt ?? $payment->submitted_at;

            if ($received->gt(now()->addMinutes(5))) {
                throw ApiException::invalid('La fecha en que llegó el dinero no puede ser futura.');
            }

            // El bono de esa semana ya está con Dirección: no entraría en él.
            $week = WeekLock::weekOfDay($received->copy()->timezone(config('app.timezone'))->toDateString());
            if ($week !== null && WeekLock::isFrozen($week, lock: true)) {
                throw ApiException::conflict(
                    'week_closed',
                    'Esa fecha es de una semana que ya se envió a Dirección.',
                );
            }

            $payment->forceFill([
                'status' => PaymentStatus::Confirmed,
                'amount_matches' => true,
                'destination_ok' => true,
                'reviewed_by' => $jime->getKey(),
                'reviewed_at' => now(),
                'received_at' => $received,
            ])->save();

            $from = $order->status;
            $order->update(['status' => OrderStatus::Paid, 'paid_at' => now()]);
            $order->recordStatus($from, $jime);

            Commissions::earn($order->getKey(), $received);
        });
    }

    public function reject(User $jime, string $paymentId, string $reason): void
    {
        DB::transaction(function () use ($jime, $paymentId, $reason) {
            [$order, $payment] = $this->lockForReview($paymentId);

            $payment->forceFill([
                'status' => PaymentStatus::Rejected,
                'reject_reason' => $reason,
                'reviewed_by' => $jime->getKey(),
                'reviewed_at' => now(),
            ])->save();

            $from = $order->status;
            $order->update(['status' => OrderStatus::PendingPayment]);
            $order->recordStatus($from, $jime, $reason);
        });
    }

    /**
     * El pedido primero y después el cobro, como en las ventas, para no
     * trabarse. Solo se revisa un comprobante que está esperando.
     *
     * @return array{Order, Payment}
     */
    private function lockForReview(string $paymentId): array
    {
        $orderId = Payment::query()->whereKey($paymentId)->value('order_id') ?? throw self::missing();
        $order = Order::query()->lockForUpdate()->findOrFail($orderId);
        $payment = Payment::query()->lockForUpdate()->findOrFail($paymentId);

        if ($payment->method === PaymentMethod::Cash) {
            throw ApiException::conflict('invalid_status', 'El efectivo se confirma al validar la caja del día.');
        }

        if ($payment->status !== PaymentStatus::Submitted || $order->status !== OrderStatus::PaymentReview) {
            throw ApiException::conflict('already_reviewed', 'Este pago ya se revisó.');
        }

        return [$order, $payment];
    }

    /** En otro pago vigente, de cualquier forma de pago. */
    private static function referenceUsed(Payment $payment): bool
    {
        return $payment->reference !== null && Payment::query()
            ->where('reference', $payment->reference)
            ->whereKeyNot($payment->getKey())
            ->whereNotIn('status', [PaymentStatus::Rejected->value, PaymentStatus::Reversed->value])
            ->exists();
    }

    private static function missing(): ApiException
    {
        return ApiException::notFound('No encontramos ese pago.');
    }
}
