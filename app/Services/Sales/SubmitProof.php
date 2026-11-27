<?php

namespace App\Services\Sales;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Support\PrivateFiles;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * La foto del comprobante: el cobro queda por revisar y el pedido «En
 * revisión». Subir otra la reemplaza; si Jime había rechazado la anterior,
 * queda como un cobro nuevo (el rechazado se conserva en el historial).
 */
class SubmitProof
{
    public function submit(
        User $seller,
        string $orderId,
        UploadedFile $file,
        ?string $reference,
        string $clientUuid,
    ): Order {
        return $this->attach(
            fn (): Order => OwnOrders::lock($seller, $orderId),
            $seller,
            $file,
            $reference,
            $clientUuid,
        );
    }

    /**
     * @param  Closure(): Order  $lock  carga y bloquea el pedido de quien envía,
     *                                  y falla si no le corresponde
     * @param  User|null  $by  quién lo sube; nulo si es el cliente de la tienda
     */
    public function attach(
        Closure $lock,
        ?User $by,
        UploadedFile $file,
        ?string $reference,
        string $clientUuid,
    ): Order {
        $reference = filled($reference) ? trim($reference) : null;
        $stored = null;

        try {
            return DB::transaction(function () use ($lock, $by, $file, $reference, $clientUuid, &$stored): Order {
                $order = $lock();

                // El mismo envío repetido (si se perdió la respuesta guardada).
                if ($order->payments()->where('client_uuid', $clientUuid)->exists()) {
                    return $order;
                }

                $current = $order->currentPayment()->first();

                if (! $order->status->isAwaitingPayment() || ! $current?->method->needsProof()) {
                    throw ApiException::conflict('already_reviewed', 'Este pedido ya no admite otro comprobante.');
                }

                $payment = $current->status === PaymentStatus::Rejected
                    ? new Payment(['order_id' => $order->getKey(), 'method' => $current->method, 'amount_bs' => $current->amount_bs])
                    : $current;

                $this->ensureNewReference($payment, $reference);

                $stored = PrivateFiles::put($file, "comprobantes/{$order->getKey()}", "comprobante-{$order->order_number}");

                $payment->fill([
                    'status' => PaymentStatus::Submitted,
                    'reference' => $reference,
                    'proof_public_id' => $stored,
                    'proof_url' => $stored,
                    'submitted_by' => $by?->getKey(),
                    'submitted_at' => now(),
                    'reject_reason' => null,
                    'client_uuid' => $clientUuid,
                ])->save();

                if ($order->status !== OrderStatus::PaymentReview) {
                    $from = $order->status;
                    $order->update(['status' => OrderStatus::PaymentReview]);
                    $order->recordStatus($from, $by);
                }

                return $order;
            });
        } catch (UniqueConstraintViolationException $e) {
            PrivateFiles::forget($stored);

            // Otra venta usó esa referencia en el mismo momento.
            throw str_contains($e->getMessage(), 'payments_reference_unique')
                ? $this->duplicatedReference()
                : $e;
        } catch (\Throwable $e) {
            PrivateFiles::forget($stored);

            throw $e;
        }
    }

    private function ensureNewReference(Payment $payment, ?string $reference): void
    {
        if ($reference === null) {
            return;
        }

        $used = Payment::query()
            ->where('method', $payment->method->value)
            ->where('reference', $reference)
            ->whereNotIn('status', [PaymentStatus::Rejected->value, PaymentStatus::Reversed->value])
            ->when($payment->exists, fn ($query) => $query->where('id_payment', '<>', $payment->getKey()))
            ->exists();

        if ($used) {
            throw $this->duplicatedReference();
        }
    }

    private function duplicatedReference(): ApiException
    {
        return ApiException::conflict('reference_duplicated', 'Ese número de referencia ya se usó en otro pago.');
    }
}
