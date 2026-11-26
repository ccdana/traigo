<?php

namespace App\Services\Control;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Comisiones por mes. Una comisión cuenta en el mes en que llegó el dinero; si
 * la liquidación de ese mes ya se envió cuando se ganó, entra en la siguiente
 * que se envíe de un mes posterior. Así ninguna se pierde.
 */
class Commissions
{
    private const SENT = ['sent', 'authorized', 'paid'];

    /** Al confirmar el cobro: las de ese pedido se ganan. */
    public static function earn(string $orderId, Carbon $received): void
    {
        DB::table('commissions')
            ->where('order_id', $orderId)
            ->where('status', 'pending')
            ->update(['status' => 'earned', 'earned_at' => $received, 'updated_at' => now()]);
    }

    /**
     * Al devolver un pedido: lo ganado se descuenta con un ajuste en el mes
     * actual y lo que faltaba cobrar se anula.
     */
    public static function reverse(string $orderId, User $by, string $reason): void
    {
        $sales = DB::table('commissions')->where('order_id', $orderId)->where('kind', 'sale')->get();

        foreach ($sales->where('status', 'earned') as $sale) {
            // La base exige el mismo redondeo: por eso lo calcula ella.
            DB::insert(<<<'SQL'
                insert into commissions
                    (id_commission, order_id, order_item_id, seller_id, kind, status, base_bs, rate_pct, amount_bs, earned_at, note, created_by)
                select ?, order_id, order_item_id, seller_id, 'adjustment', 'earned', -base_bs, rate_pct,
                       round(-base_bs * rate_pct / 100, 2), ?, ?, ?
                from commissions where id_commission = ?
                SQL, [(string) Str::uuid7(), now(), $reason, $by->getKey(), $sale->id_commission]);
        }

        self::cancelPending($orderId);
    }

    public static function cancelPending(string $orderId): void
    {
        DB::table('commissions')
            ->where('order_id', $orderId)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled', 'updated_at' => now()]);
    }

    /** @return array<string, mixed> `month` como «2026-12» */
    public static function month(string $month): array
    {
        $period = self::period($month);
        $settlement = DB::table('commission_settlements')->where('period', $period->toDateString())->first();
        $status = $settlement->status ?? 'open';

        $rows = self::rows($period, $settlement)->get(['c.seller_id', 'c.order_id', 'c.kind', 'c.amount_bs']);

        $members = self::sellers()->map(fn (object $seller) => self::row($seller, $rows->where('seller_id', $seller->id_user)));
        $ratesPending = $rows->contains(fn (object $row) => $row->amount_bs === null);

        return [
            'month' => $month,
            'status' => $status,
            'rates_pending' => $ratesPending,
            'total' => $ratesPending ? null : self::sum($rows),
            'settles_on' => $period->copy()->endOfMonth()->toDateString(),
            'return_reason' => $status === 'returned' ? $settlement->return_reason : null,
            'members' => $members->values()->all(),
        ];
    }

    /** Cada comisión de la liquidación de ese mes, para el reporte. */
    public static function lines(string $month): Collection
    {
        $period = self::period($month);
        $settlement = DB::table('commission_settlements')->where('period', $period->toDateString())->first();

        return self::rows($period, $settlement)
            ->join('orders as o', 'o.id_order', '=', 'c.order_id')
            ->join('users as u', 'u.id_user', '=', 'c.seller_id')
            ->leftJoin('order_items as i', 'i.id_order_item', '=', 'c.order_item_id')
            ->orderBy('u.name')
            ->orderBy('c.earned_at')
            ->orderBy('o.order_number')
            ->get([
                'c.earned_at', 'c.kind', 'c.base_bs', 'c.rate_pct', 'c.amount_bs', 'o.order_number',
                'u.name as seller', 'i.item_code', 'i.product_name', 'i.quantity',
            ]);
    }

    /** Enviada, se muestra como se envió. */
    private static function rows(Carbon $period, ?object $settlement): Builder
    {
        return $settlement && in_array($settlement->status, self::SENT, true)
            ? DB::table('commissions as c')->where('c.settlement_id', $settlement->id_commission_settlement)
            : self::open($period, $settlement?->id_commission_settlement);
    }

    /** Manda la liquidación del mes a Dirección, solo cuando el mes terminó. */
    public function send(User $jime, string $month): void
    {
        $period = self::period($month);

        if (today()->lt($period->copy()->addMonth())) {
            throw ApiException::conflict('month_not_ended', 'La liquidación se envía cuando termina el mes.');
        }

        DB::transaction(function () use ($jime, $period) {
            // De a un envío por vez: si no, dos podrían llevarse la misma.
            DB::select("select pg_advisory_xact_lock(hashtext('traigo.comisiones'))");

            $settlement = DB::table('commission_settlements')
                ->where('period', $period->toDateString())
                ->lockForUpdate()
                ->first();

            if ($settlement && in_array($settlement->status, self::SENT, true)) {
                throw ApiException::conflict('closing_already_sent', 'La liquidación de ese mes ya se envió.');
            }

            $rows = self::open($period, $settlement?->id_commission_settlement)
                ->lock('for update of c')
                ->get(['c.id_commission', 'c.amount_bs']);

            if ($rows->isEmpty()) {
                throw ApiException::conflict('nothing_to_settle', 'No hay comisiones cobradas en ese mes.');
            }

            if ($rows->contains(fn (object $row) => $row->amount_bs === null)) {
                throw ApiException::conflict('rates_pending', 'Hay ventas sin % de comisión definido.');
            }

            $values = [
                'status' => 'sent',
                'sent_by' => $jime->getKey(),
                'sent_at' => now(),
                'authorized_by' => null,
                'authorized_at' => null,
                'return_reason' => null,
                'updated_at' => now(),
            ];
            $id = $settlement?->id_commission_settlement ?? (string) Str::uuid7();

            if ($settlement) {
                DB::table('commission_settlements')->where('id_commission_settlement', $id)->update($values);
            } else {
                DB::table('commission_settlements')->insert([
                    'id_commission_settlement' => $id, 'period' => $period->toDateString(), ...$values,
                ]);
            }

            $ids = $rows->pluck('id_commission')->all();
            DB::table('commissions')->where('settlement_id', $id)->whereNotIn('id_commission', $ids)
                ->update(['settlement_id' => null, 'updated_at' => now()]);
            DB::table('commissions')->whereIn('id_commission', $ids)->whereNull('settlement_id')
                ->update(['settlement_id' => $id, 'updated_at' => now()]);
        });
    }

    public function markPaid(string $month): void
    {
        $period = self::period($month);

        DB::transaction(function () use ($period) {
            $settlement = DB::table('commission_settlements')
                ->where('period', $period->toDateString())
                ->lockForUpdate()
                ->first();

            if ($settlement?->status !== 'authorized') {
                throw ApiException::conflict(
                    'not_ready_to_pay',
                    $settlement?->status === 'paid'
                        ? 'Esa liquidación ya está pagada.'
                        : 'Todavía no lo autorizó Dirección.',
                );
            }

            DB::table('commission_settlements')
                ->where('id_commission_settlement', $settlement->id_commission_settlement)
                ->update(['status' => 'paid', 'paid_at' => now(), 'updated_at' => now()]);
        });
    }

    /** Lo que entra en la liquidación abierta (o devuelta) de ese mes. */
    private static function open(Carbon $period, ?string $settlementId): Builder
    {
        $month = "date_trunc('month', c.earned_at at time zone 'America/La_Paz')::date";
        $day = $period->toDateString();

        return DB::table('commissions as c')
            ->where('c.status', 'earned')
            ->where(function (Builder $query) use ($month, $day, $settlementId) {
                $query->where(function (Builder $free) use ($month, $day) {
                    $free->whereNull('c.settlement_id')->where(function (Builder $mine) use ($month, $day) {
                        $mine->whereRaw("{$month} = ?", [$day])
                            // De un mes que ya se envió: se ganó después.
                            ->orWhere(function (Builder $late) use ($month, $day) {
                                $late->whereRaw("{$month} < ?", [$day])
                                    ->whereExists(fn (Builder $sent) => $sent->from('commission_settlements as s')
                                        ->whereRaw("s.period = {$month}")
                                        ->whereIn('s.status', self::SENT));
                            });
                    });
                });

                if ($settlementId !== null) {
                    $query->orWhere('c.settlement_id', $settlementId);
                }
            });
    }

    /** Quienes venden, en el orden de la app. */
    private static function sellers(): Collection
    {
        return DB::table('users as u')
            ->join('team_profiles as p', 'p.user_id', '=', 'u.id_user')
            ->whereIn('u.role', ['seller', 'wholesale'])
            ->orderBy('p.sort_order')
            ->get(['u.id_user', 'u.name']);
    }

    private static function row(object $seller, Collection $rows): array
    {
        return [
            'id' => $seller->id_user,
            'name' => $seller->name,
            'amount' => $rows->contains(fn (object $row) => $row->amount_bs === null) ? null : self::sum($rows),
            'paid_sales' => $rows->where('kind', 'sale')->unique('order_id')->count(),
            // Lo que se descontó por devoluciones (lo sin % no suma todavía).
            'adjustments' => self::sum($rows->where('kind', 'adjustment')),
        ];
    }

    private static function sum(Collection $rows): string
    {
        return Money::format($rows->sum(fn (object $row) => $row->amount_bs === null ? 0 : Money::toCents($row->amount_bs)));
    }

    private static function period(string $month): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', "{$month}-01", config('app.timezone'))->startOfDay();
    }
}
