<?php

namespace App\Services\Cash;

use App\Models\BonusWeek;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La caja de hoy resumida, para el Inicio de almacén. La caja completa (líneas,
 * pendientes y cierre) llega con la fase de almacén.
 */
final class CashSummary
{
    /**
     * @return array{collected: string, paid_orders: int, pending_orders: int, status: string, pending_days: int}
     */
    public static function today(): array
    {
        $today = today();
        $closing = DB::table('cash_closings')->where('business_date', $today->toDateString())->first();

        // Enviada o validada, se muestra como se envió.
        [$collected, $paidOrders] = $closing && in_array($closing->status, ['sent', 'validated'], true)
            ? self::closed($closing->id_cash_closing)
            : self::collections($today);

        return [
            'collected' => Money::format($collected),
            'paid_orders' => $paidOrders,
            'pending_orders' => DB::table('orders')
                ->where('status', 'pending_payment')
                ->where('created_at', '>=', $today)
                ->where('created_at', '<', $today->copy()->addDay())
                ->count(),
            'status' => $closing->status ?? 'open',
            'pending_days' => self::pendingDays($today),
        ];
    }

    /** @return array{0: int, 1: int} centavos y pedidos cobrados */
    private static function closed(string $closingId): array
    {
        $lines = DB::table('cash_closing_lines')->where('cash_closing_id', $closingId)->get();

        return [
            $lines->sum(fn ($line) => Money::toCents($line->expected_bs)),
            (int) $lines->sum('orders_count'),
        ];
    }

    /**
     * Los pagos enviados o confirmados que llegaron ese día (mientras no se
     * confirman, cuentan por cuándo se subieron).
     *
     * @return array{0: int, 1: int}
     */
    private static function collections(Carbon $day): array
    {
        $row = DB::table('payments as p')
            ->join('orders as o', 'o.id_order', '=', 'p.order_id')
            ->whereIn('p.status', ['submitted', 'confirmed'])
            ->whereNotIn('o.status', ['cancelled', 'returned', 'expired'])
            ->whereRaw("(coalesce(p.received_at, p.submitted_at) at time zone 'America/La_Paz')::date = ?", [$day->toDateString()])
            ->selectRaw('coalesce(sum(p.amount_bs), 0) as total, count(distinct p.order_id) as orders')
            ->first();

        return [Money::toCents($row->total), (int) $row->orders];
    }

    /**
     * Días anteriores de la campaña sin enviar o que Jime observó: cada día se
     * cierra, aunque no haya cobros.
     */
    private static function pendingDays(Carbon $today): int
    {
        $start = BonusWeek::query()->min('starts_on');

        if ($start === null || $today->toDateString() <= $start) {
            return 0;
        }

        $closed = DB::table('cash_closings')
            ->where('business_date', '>=', $start)
            ->where('business_date', '<', $today->toDateString())
            ->whereIn('status', ['sent', 'validated'])
            ->count();

        return (int) Carbon::parse($start)->diffInDays($today->copy()->startOfDay()) - $closed;
    }
}
