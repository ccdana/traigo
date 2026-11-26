<?php

namespace App\Services\Cash;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Models\BonusWeek;
use App\Models\Order;
use App\Support\Dates;
use App\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * La caja de un día, de todo el equipo. Desde el primer día de la campaña cada
 * día se cierra, aunque no haya cobros.
 *
 * Cada cobro queda en un solo cierre: el de su día (cuándo llegó el dinero) o,
 * si llegó después de que ese día se envió, el siguiente que se envíe de un día
 * posterior. Lo cobrado antes de la campaña entra en el primer día.
 */
final class CashDay
{
    /** Enviado o validado: ya no cambia. */
    public const SENT = ['sent', 'validated'];

    public static function firstDay(): ?string
    {
        $first = BonusWeek::query()->min('starts_on');

        return $first === null ? null : Carbon::parse($first)->toDateString();
    }

    public static function requireCashDay(string $date): void
    {
        $first = self::firstDay();

        if ($first === null || $date < $first || $date > today()->toDateString()) {
            throw ApiException::notFound('Ese día no tiene caja.');
        }
    }

    /**
     * Como lo pide el contrato (`GET /cash/today`).
     *
     * @return array<string, mixed>
     */
    public static function show(string $date, bool $withPendingDays = false): array
    {
        $closing = DB::table('cash_closings as c')
            ->join('users as u', 'u.id_user', '=', 'c.closed_by')
            ->where('c.business_date', $date)
            ->first(['c.*', 'u.name as closed_by_name']);

        $lines = self::lines($date, $closing);
        $pending = self::pending($date);
        $sent = $closing?->sent_at !== null;

        return [
            'closing_id' => $closing?->id_cash_closing,
            'business_date' => $date,
            'status' => $closing->status ?? 'open',
            'collected' => Money::format(array_sum(array_column($lines, 'expected'))),
            'paid_orders' => array_sum(array_column($lines, 'orders_count')),
            'pending_orders' => count($pending),
            'lines' => array_map(fn (array $line) => [
                'method' => $line['method'],
                'orders_count' => $line['orders_count'],
                'expected' => Money::format($line['expected']),
                'counted' => $line['counted'] === null ? null : Money::format($line['counted']),
                'note' => $line['note'],
            ], $lines),
            'pending' => $pending,
            ...($withPendingDays ? ['pending_days' => self::pendingDays()] : []),
            'closed_by_name' => $sent ? $closing->closed_by_name : null,
            'sent_at' => Dates::toApi($closing?->sent_at),
            'note' => $closing?->note,
            'review_note' => $closing?->review_note,
        ];
    }

    /** @return array{collected: string, paid_orders: int, pending_orders: int, status: string, pending_days: int} */
    public static function homeSummary(): array
    {
        $day = self::show(today()->toDateString(), withPendingDays: true);

        return [
            'collected' => $day['collected'],
            'paid_orders' => $day['paid_orders'],
            'pending_orders' => $day['pending_orders'],
            'status' => $day['status'],
            'pending_days' => count($day['pending_days']),
        ];
    }

    /**
     * Los pagos enviados o confirmados que van en la caja de [date]: los que ya
     * quedaron en su cierre y los que todavía no están en ninguno y le tocan.
     * Mientras no se confirman, cuentan por cuándo se subieron.
     */
    public static function collections(string $date, ?string $closingId): Builder
    {
        $day = "(coalesce(p.received_at, p.submitted_at) at time zone 'America/La_Paz')::date";
        $first = self::firstDay();
        $bindings = [];

        if ($first !== null && $date >= $first) {
            $day = "greatest({$day}, ?::date)";
            $bindings = [$first];
        }

        return DB::table('payments as p')
            ->join('orders as o', 'o.id_order', '=', 'p.order_id')
            ->whereIn('p.status', [PaymentStatus::Submitted->value, PaymentStatus::Confirmed->value])
            ->whereNotIn('o.status', ['cancelled', 'returned', 'expired'])
            ->where(function (Builder $query) use ($date, $closingId, $day, $bindings) {
                $query->where(function (Builder $free) use ($date, $day, $bindings) {
                    $free->whereNull('p.cash_closing_id')->where(function (Builder $mine) use ($date, $day, $bindings) {
                        $mine->whereRaw("{$day} = ?", [...$bindings, $date])
                            // De un día que ya se envió: llegó después.
                            ->orWhere(function (Builder $late) use ($date, $day, $bindings) {
                                $late->whereRaw("{$day} < ?", [...$bindings, $date])
                                    ->whereExists(fn (Builder $sent) => $sent->from('cash_closings as s')
                                        ->whereRaw("s.business_date = {$day}", $bindings)
                                        ->whereIn('s.status', self::SENT));
                            });
                    });
                });

                if ($closingId !== null) {
                    $query->orWhere('p.cash_closing_id', $closingId);
                }
            });
    }

    /**
     * Lo que debería haber por forma de pago. La tarjeta solo existe en la
     * tienda web, así que aparece solo si hubo.
     *
     * @param  Collection<int, object{method: string, order_id: string, amount_bs: string}>  $payments
     * @return list<array{method: string, orders_count: int, expected: int}> en centavos
     */
    public static function expected(Collection $payments): array
    {
        $byMethod = $payments->groupBy('method');

        return collect(PaymentMethod::cases())
            ->filter(fn (PaymentMethod $method) => $method !== PaymentMethod::Card || $byMethod->has($method->value))
            ->map(function (PaymentMethod $method) use ($byMethod) {
                $mine = $byMethod->get($method->value, collect());

                return [
                    'method' => $method->value,
                    'orders_count' => $mine->unique('order_id')->count(),
                    'expected' => $mine->sum(fn (object $payment) => Money::toCents($payment->amount_bs)),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Enviado o validado se muestra como se envió; observado se recalcula para
     * corregirlo, con lo que se había contado.
     *
     * @return list<array{method: string, orders_count: int, expected: int, counted: ?int, note: ?string}>
     */
    private static function lines(string $date, ?object $closing): array
    {
        $saved = $closing
            ? DB::table('cash_closing_lines')->where('cash_closing_id', $closing->id_cash_closing)->get()->keyBy('method')
            : collect();

        if ($closing && in_array($closing->status, self::SENT, true)) {
            return collect(PaymentMethod::cases())
                ->map(fn (PaymentMethod $method) => $saved->get($method->value))
                ->filter()
                ->map(fn (object $line) => [
                    'method' => $line->method,
                    'orders_count' => $line->orders_count,
                    'expected' => Money::toCents($line->expected_bs),
                    'counted' => Money::toCents($line->counted_bs),
                    'note' => $line->note,
                ])
                ->values()
                ->all();
        }

        $payments = self::collections($date, $closing?->id_cash_closing)
            ->get(['p.method', 'p.order_id', 'p.amount_bs']);

        return array_map(function (array $line) use ($saved) {
            $previous = $saved->get($line['method']);

            return [
                ...$line,
                'counted' => $previous === null ? null : Money::toCents($previous->counted_bs),
                'note' => $previous?->note,
            ];
        }, self::expected($payments));
    }

    /**
     * Pedidos de ese día que todavía no se cobraron.
     *
     * @return list<array<string, mixed>>
     */
    private static function pending(string $date): array
    {
        $start = Carbon::parse($date, config('app.timezone'))->startOfDay();

        return Order::query()
            ->with(['currentPayment', 'seller'])
            ->where('status', 'pending_payment')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $start->copy()->addDay())
            ->orderBy('created_at')
            ->orderBy('order_number')
            ->get()
            ->filter(fn (Order $order) => $order->currentPayment !== null)
            ->map(fn (Order $order) => [
                'order_id' => $order->getKey(),
                'number' => $order->order_number,
                'customer_name' => $order->customer_name,
                'payment_method' => $order->currentPayment->method->value,
                'total' => Money::fromDatabase($order->total_bs),
                'seller_name' => $order->seller?->name,
                'detail' => $order->currentPayment->status === PaymentStatus::Rejected
                    ? 'comprobante rechazado'
                    : 'sin comprobante',
            ])
            ->values()
            ->all();
    }

    /**
     * Días anteriores de la campaña sin enviar o que Jime observó, del más
     * antiguo al más nuevo.
     *
     * @return list<array{date: string, status: string}>
     */
    private static function pendingDays(): array
    {
        $first = self::firstDay();
        $today = today()->toDateString();

        if ($first === null || $first >= $today) {
            return [];
        }

        $statuses = DB::table('cash_closings')
            ->where('business_date', '>=', $first)
            ->where('business_date', '<', $today)
            ->pluck('status', 'business_date');
        $days = [];

        for ($day = Carbon::parse($first); $day->toDateString() < $today; $day->addDay()) {
            $status = $statuses->get($day->toDateString(), 'open');

            if (! in_array($status, self::SENT, true)) {
                $days[] = ['date' => $day->toDateString(), 'status' => $status];
            }
        }

        return $days;
    }
}
