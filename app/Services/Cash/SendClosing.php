<?php

namespace App\Services\Cash;

use App\Enums\PaymentMethod;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Almacén envía la caja de un día a Jime: lo que dicen los pedidos contra lo
 * contado. Los cobros incluidos quedan ligados a este cierre. Si Jime lo
 * observó, se vuelve a enviar (hay un cierre por fecha).
 */
class SendClosing
{
    /** @param  array<string, int>  $counted  centavos contados por forma de pago */
    public function send(User $warehouse, string $date, array $counted, ?string $note): void
    {
        $note = filled($note) ? trim($note) : null;

        DB::transaction(function () use ($warehouse, $date, $counted, $note) {
            // De a un cierre por vez: si no, dos podrían llevarse el mismo
            // cobro atrasado.
            DB::select("select pg_advisory_xact_lock(hashtext('traigo.cierre_de_caja'))");
            CashDay::requireCashDay($date);

            $closing = DB::table('cash_closings')->where('business_date', $date)->lockForUpdate()->first();

            if ($closing && in_array($closing->status, CashDay::SENT, true)) {
                throw ApiException::conflict('closing_already_sent', 'El cierre de ese día ya se envió.');
            }

            $payments = CashDay::collections($date, $closing?->id_cash_closing)
                ->lock('for update of p')
                ->get(['p.id_payment', 'p.method', 'p.order_id', 'p.amount_bs']);
            $lines = $this->countedLines(CashDay::expected($payments), $counted);

            if ($note === null && collect($lines)->contains(fn ($line) => $line['counted'] !== $line['expected'])) {
                throw ApiException::invalid('La caja no cuadra: explica la diferencia.', 'difference_needs_note');
            }

            $closingId = $this->save($warehouse, $date, $note, $closing?->id_cash_closing);
            $this->saveLines($closingId, $lines);
            $this->link($closingId, $payments->pluck('id_payment')->all());
        });
    }

    /**
     * Lo contado de cada forma de pago; se exige para todas las que tienen
     * línea. Si se contó plata de otra, también queda (como diferencia).
     *
     * @param  list<array{method: string, orders_count: int, expected: int}>  $expected
     * @param  array<string, int>  $counted
     * @return list<array{method: string, orders_count: int, expected: int, counted: int}>
     */
    private function countedLines(array $expected, array $counted): array
    {
        $lines = [];

        foreach ($expected as $line) {
            $amount = $counted[$line['method']]
                ?? throw ApiException::invalid('Falta lo recibido por '.PaymentMethod::from($line['method'])->label().'.');
            $lines[] = [...$line, 'counted' => $amount];
        }

        foreach (array_diff_key($counted, array_column($expected, null, 'method')) as $method => $amount) {
            if ($amount !== 0) {
                $lines[] = ['method' => $method, 'orders_count' => 0, 'expected' => 0, 'counted' => $amount];
            }
        }

        return $lines;
    }

    private function save(User $warehouse, string $date, ?string $note, ?string $closingId): string
    {
        $values = [
            'status' => 'sent',
            'closed_by' => $warehouse->getKey(),
            'note' => $note,
            'sent_at' => now(),
            // Se vuelve a revisar: lo observado queda en la auditoría.
            'validated_by' => null,
            'validated_at' => null,
            'review_note' => null,
            'updated_at' => now(),
        ];

        if ($closingId !== null) {
            DB::table('cash_closings')->where('id_cash_closing', $closingId)->update($values);

            return $closingId;
        }

        $closingId = (string) Str::uuid7();
        DB::table('cash_closings')->insert(['id_cash_closing' => $closingId, 'business_date' => $date, ...$values]);

        return $closingId;
    }

    /** @param  list<array{method: string, orders_count: int, expected: int, counted: int}>  $lines */
    private function saveLines(string $closingId, array $lines): void
    {
        DB::table('cash_closing_lines')
            ->where('cash_closing_id', $closingId)
            ->whereNotIn('method', array_column($lines, 'method'))
            ->delete();

        foreach ($lines as $line) {
            DB::table('cash_closing_lines')->updateOrInsert(
                ['cash_closing_id' => $closingId, 'method' => $line['method']],
                [
                    'orders_count' => $line['orders_count'],
                    'expected_bs' => Money::format($line['expected']),
                    'counted_bs' => Money::format($line['counted']),
                ],
            );
        }
    }

    /** @param  list<string>  $paymentIds */
    private function link(string $closingId, array $paymentIds): void
    {
        // Al reenviar, lo que ya no cuenta (rechazado o anulado) queda libre.
        DB::table('payments')
            ->where('cash_closing_id', $closingId)
            ->whereNotIn('id_payment', $paymentIds)
            ->update(['cash_closing_id' => null, 'updated_at' => now()]);

        DB::table('payments')
            ->whereIn('id_payment', $paymentIds)
            ->whereNull('cash_closing_id')
            ->update(['cash_closing_id' => $closingId, 'updated_at' => now()]);
    }
}
