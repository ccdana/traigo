<?php

namespace App\Services\Control;

use App\Enums\PaymentMethod;
use App\Services\Team\WeekLock;
use App\Support\Dates;
use App\Support\Lists;
use App\Support\Money;
use App\Support\Numbers;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lo que Jime tiene por revisar: comprobantes, evidencia, cierres de caja y
 * entregas de Sistemas, lo más antiguo primero.
 */
final class PendingInbox
{
    public const TYPES = ['payment', 'evidence', 'cash_closing', 'deliverable'];

    /** @return list<array<string, mixed>> */
    public static function items(?string $type = null): array
    {
        return collect($type === null ? self::TYPES : [$type])
            ->flatMap(fn (string $type) => self::of($type))
            ->sortBy(fn (array $item) => [strtotime($item['created_at']), $item['id']])
            ->map(fn (array $item) => [...$item, 'created_at' => Dates::toApi($item['created_at'])])
            ->values()
            ->all();
    }

    /** @return array{payments: int, evidence: int, cash_closings: int, deliverables: int} */
    public static function counts(): array
    {
        return [
            'payments' => self::of('payment')->count(),
            'evidence' => self::of('evidence')->count(),
            'cash_closings' => self::of('cash_closing')->count(),
            'deliverables' => self::of('deliverable')->count(),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private static function of(string $type): Collection
    {
        return match ($type) {
            'payment' => self::payments(),
            'evidence' => self::evidence(),
            'cash_closing' => self::cashClosings(),
            'deliverable' => self::deliverables(),
        };
    }

    /** Comprobantes sin revisar. El efectivo se confirma con la caja. */
    private static function payments(): Collection
    {
        return DB::table('payments as p')
            ->join('orders as o', 'o.id_order', '=', 'p.order_id')
            ->leftJoin('users as s', 's.id_user', '=', 'o.seller_id')
            ->where('p.status', 'submitted')
            ->where('p.method', '<>', PaymentMethod::Cash->value)
            ->where('o.status', 'payment_review')
            ->get(['p.id_payment', 'p.method', 'p.amount_bs', 'p.submitted_at', 'o.customer_name', 's.name as seller'])
            ->map(fn (object $row) => [
                'type' => 'payment',
                'id' => $row->id_payment,
                'title' => $row->customer_name.' · '.($row->seller ?? 'Tienda web'),
                'subtitle' => PaymentMethod::from($row->method)->label(),
                'created_at' => $row->submitted_at,
                'amount' => Money::fromDatabase($row->amount_bs),
            ]);
    }

    /** Un elemento por indicador, desde su evidencia más antigua sin revisar. */
    private static function evidence(): Collection
    {
        return DB::table('kpi_evidence as e')
            ->join('v_kpi_results as r', 'r.id_kpi_weekly_result', '=', 'e.kpi_weekly_result_id')
            ->join('kpi_indicators as i', 'i.id_kpi_indicator', '=', 'r.indicator_id')
            ->join('users as u', 'u.id_user', '=', 'r.user_id')
            ->where('e.status', 'in_review')
            ->groupBy('r.id_kpi_weekly_result', 'u.name', 'i.name', 'r.target')
            ->selectRaw('r.id_kpi_weekly_result as id, u.name as member, i.name as indicator, r.target, count(*) as total, min(e.submitted_at) as since')
            ->get()
            ->map(fn (object $row) => [
                'type' => 'evidence',
                'id' => $row->id,
                'title' => "{$row->member} · {$row->indicator}",
                'subtitle' => $row->total.($row->total === 1 ? ' evidencia' : ' evidencias')
                    .' · meta '.Numbers::plain($row->target),
                'created_at' => $row->since,
                'amount' => null,
            ]);
    }

    private static function cashClosings(): Collection
    {
        $closings = DB::table('cash_closings as c')
            ->join('users as u', 'u.id_user', '=', 'c.closed_by')
            ->where('c.status', 'sent')
            ->get(['c.id_cash_closing', 'c.business_date', 'c.sent_at', 'u.name']);
        $lines = DB::table('cash_closing_lines')
            ->whereIn('cash_closing_id', $closings->pluck('id_cash_closing'))
            ->get(['cash_closing_id', 'method', 'expected_bs'])
            ->groupBy('cash_closing_id');

        return $closings->map(function (object $row) use ($lines): array {
            $mine = $lines->get($row->id_cash_closing, collect());
            $methods = collect(PaymentMethod::cases())
                ->filter(fn (PaymentMethod $method) => $mine->contains('method', $method->value))
                ->map(fn (PaymentMethod $method) => $method === PaymentMethod::Qr
                    ? $method->label()
                    : mb_strtolower($method->label()));

            return [
                'type' => 'cash_closing',
                'id' => $row->id_cash_closing,
                'title' => "{$row->name} · cierre del ".Dates::weekday($row->business_date),
                'subtitle' => Lists::join($methods->values()->all()),
                'created_at' => $row->sent_at,
                'amount' => Money::format($mine->sum(fn (object $line) => Money::toCents($line->expected_bs))),
            ];
        });
    }

    /** Enviadas sin revisar, de semanas que todavía se pueden cambiar. */
    private static function deliverables(): Collection
    {
        return DB::table('system_deliverables as d')
            ->join('users as u', 'u.id_user', '=', 'd.user_id')
            ->leftJoin('weekly_closings as c', 'c.week_id', '=', 'd.week_id')
            ->whereNotNull('d.delivered_on')
            ->whereNotNull('d.evidence_url')
            ->whereNull('d.jime_validated')
            ->whereNotIn(DB::raw("coalesce(c.status::text, 'open')"), WeekLock::FROZEN)
            ->get(['d.id_system_deliverable', 'd.title', 'd.updated_at', 'u.name'])
            ->map(fn (object $row) => [
                'type' => 'deliverable',
                'id' => $row->id_system_deliverable,
                'title' => $row->name,
                'subtitle' => $row->title,
                // La hora en que se envió.
                'created_at' => $row->updated_at,
                'amount' => null,
            ]);
    }
}
