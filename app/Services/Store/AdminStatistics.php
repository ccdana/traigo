<?php

namespace App\Services\Store;

use App\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Consultas de solo lectura; no modifica los reportes ni cierres de la app. */
final class AdminStatistics
{
    private function orders(string $channel): Builder
    {
        return DB::table('orders as o')
            ->when($channel === 'web', fn ($q) => $q->where('o.origin', 'web'))
            ->when($channel === 'team', fn ($q) => $q->where('o.origin', '<>', 'web'));
    }

    public function forPeriod(Carbon $from, Carbon $until, string $channel): array
    {
        $end = $until->copy()->addDay();
        $created = $this->orders($channel)->where('o.created_at', '>=', $from)->where('o.created_at', '<', $end);
        $paid = $this->orders($channel)
            ->join('payments as p', 'p.order_id', '=', 'o.id_order')
            ->where('p.status', 'confirmed')
            ->whereNotIn('o.status', ['cancelled', 'returned', 'expired'])
            ->where('p.received_at', '>=', $from)->where('p.received_at', '<', $end);
        $collected = Money::toCents((string) (clone $paid)->sum('p.amount_bs'));
        $daily = (clone $paid)
            ->selectRaw("(p.received_at at time zone 'America/La_Paz')::date as day, sum(p.amount_bs) as amount")
            ->groupByRaw("(p.received_at at time zone 'America/La_Paz')::date")
            ->pluck('amount', 'day');
        $days = [];
        for ($day = $from->copy(); $day->lt($end); $day->addDay()) {
            $days[] = ['date' => $day->toDateString(), 'label' => $day->format('d/m'), 'cents' => Money::toCents((string) ($daily[$day->toDateString()] ?? '0'))];
        }
        $delivered = $this->orders($channel)->where('o.status', 'delivered')
            ->where('o.delivered_at', '>=', $from)->where('o.delivered_at', '<', $end)
            ->join('order_items as i', 'i.order_id', '=', 'o.id_order');
        $top = (clone $delivered)->groupBy('i.item_code')
            ->selectRaw('i.item_code, max(i.product_name) as name, sum(i.quantity)::int as units, sum(i.line_total_bs) as amount')
            ->orderByDesc('units')->orderBy('i.item_code')->limit(8)->get();
        $pending = $this->orders($channel)
            ->selectRaw("count(*) filter (where status = 'payment_review')::int as review,
                count(*) filter (where status in ('paid', 'ready'))::int as dispatch")
            ->first();
        $deliveries = $this->orders($channel)->where('o.delivery_type', 'delivery')
            ->whereIn('o.status', ['paid', 'ready'])
            ->leftJoin('order_delivery_locations as l', 'l.order_id', '=', 'o.id_order')
            ->orderBy('o.paid_at')->limit(20)
            ->get(['o.order_number', 'o.customer_name', 'o.shipping_address', 'o.status', 'l.latitude', 'l.longitude', 'l.address', 'l.reference']);

        return [
            'ordersCount' => (clone $created)->count(),
            'cancelledCount' => (clone $created)->whereIn('o.status', ['cancelled', 'returned', 'expired'])->count(),
            'collectedCents' => $collected,
            'paidCount' => (clone $paid)->distinct()->count('o.id_order'),
            'deliveredUnits' => (int) (clone $delivered)->sum('i.quantity'),
            'days' => $days,
            'peakCents' => max(1, ...array_column($days, 'cents')),
            'topProducts' => $top,
            'pending' => $pending,
            'deliveries' => $deliveries,
        ];
    }
}
