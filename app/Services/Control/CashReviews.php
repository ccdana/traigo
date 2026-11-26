<?php

namespace App\Services\Control;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Cash\CashDay;
use App\Services\Team\WeekLock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Jime revisa el cierre de caja que envió almacén. Al validarlo, queda
 * confirmado el efectivo de ese cierre (también el que llegó tarde y entró en
 * él); si algo no está bien, pide corregirlo.
 */
class CashReviews
{
    /** @return array<string, mixed> como la caja del día */
    public static function view(string $closingId): array
    {
        $date = DB::table('cash_closings')->where('id_cash_closing', $closingId)->value('business_date')
            ?? throw self::missing();

        return CashDay::show($date);
    }

    public function approve(User $jime, string $closingId): void
    {
        DB::transaction(function () use ($jime, $closingId) {
            $this->lockSent($jime, $closingId);

            DB::table('cash_closings')->where('id_cash_closing', $closingId)->update([
                'status' => 'validated',
                'validated_by' => $jime->getKey(),
                'validated_at' => now(),
                'updated_at' => now(),
            ]);

            $this->confirmCash($jime, $closingId);
        });
    }

    public function observe(User $jime, string $closingId, string $note): void
    {
        DB::transaction(function () use ($jime, $closingId, $note) {
            $this->lockSent($jime, $closingId);

            DB::table('cash_closings')->where('id_cash_closing', $closingId)->update([
                'status' => 'observed',
                'review_note' => $note,
                'validated_by' => $jime->getKey(),
                'validated_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /** El efectivo se cobró cuando lo registró quien vendió. */
    private function confirmCash(User $jime, string $closingId): void
    {
        $payments = DB::table('payments')
            ->where('cash_closing_id', $closingId)
            ->where('method', PaymentMethod::Cash->value)
            ->where('status', PaymentStatus::Submitted->value)
            ->lockForUpdate()
            ->get(['id_payment', 'order_id', 'submitted_at']);

        foreach ($payments as $payment) {
            $received = Carbon::parse($payment->submitted_at)->timezone(config('app.timezone'));
            $week = WeekLock::weekOfDay($received->toDateString());

            if ($week !== null) {
                WeekLock::ensureOpen($week);
            }

            DB::table('payments')->where('id_payment', $payment->id_payment)->update([
                'status' => PaymentStatus::Confirmed->value,
                'reviewed_by' => $jime->getKey(),
                'reviewed_at' => now(),
                'received_at' => $payment->submitted_at,
                'updated_at' => now(),
            ]);
            Commissions::earn($payment->order_id, $received);
        }
    }

    private function lockSent(User $jime, string $closingId): void
    {
        $closing = DB::table('cash_closings')->where('id_cash_closing', $closingId)->lockForUpdate()->first()
            ?? throw self::missing();

        if ($closing->status !== 'sent') {
            throw ApiException::conflict('already_reviewed', 'Ese cierre ya se revisó.');
        }

        if ($closing->closed_by === $jime->getKey()) {
            throw ApiException::conflict('own_record', 'Quien cierra la caja no la valida.');
        }
    }

    private static function missing(): ApiException
    {
        return ApiException::notFound('No encontramos ese cierre.');
    }
}
