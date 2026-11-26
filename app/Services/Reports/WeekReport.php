<?php

namespace App\Services\Reports;

use App\Enums\PaymentMethod;
use App\Models\BonusWeek;
use App\Models\SystemDeliverable;
use App\Services\Control\Compliance;
use App\Services\Control\WeekBonusData;
use App\Services\Control\WeeklyClosings;
use App\Support\Numbers;
use Illuminate\Support\Facades\DB;

/** El reporte de una semana: cierre, KPI, bono, pagos y entregas. */
final class WeekReport
{
    private const STATUS = [
        'open' => 'Abierto', 'sent' => 'Enviado a Dirección', 'returned' => 'Devuelto a Jime',
        'authorized' => 'Autorizado', 'paid' => 'Pagado',
    ];

    private const TIERS = ['none' => 'Sin premio', 'partial' => 'Parcial', 'complete' => 'Completo', 'superior' => 'Superior'];

    private const RESULTS = ['pending' => 'Pendiente', 'passes' => 'Cumple', 'fails' => 'No cumple'];

    private const PAYMENT_STATUS = ['submitted' => 'Por revisar', 'confirmed' => 'Confirmado', 'reversed' => 'Devuelto'];

    private const DELIVERABLE_STATUS = [
        'pending' => 'Sin entregar', 'in_review' => 'En revisión', 'on_time' => 'A tiempo',
        'late' => 'Fuera de plazo', 'not_validated' => 'No validada',
    ];

    public static function of(BonusWeek $week): Workbook
    {
        $bonus = WeekBonusData::of($week);
        $compliance = Compliance::members($week)->keyBy('user_id');

        $book = (new Workbook)
            ->sheet('Cierre', [['Concepto', 28], ['Detalle', 60]], self::closing($week, $bonus))
            ->sheet('KPI', [
                ['Integrante', 16], ['Indicador', 44], ['Cómo se verifica', 44], ['Meta', 10],
                ['Real', 10], ['Peso %', 9], ['Avance %', 10], ['Aporta %', 10],
            ], self::indicators($week))
            ->sheet('Bono', [
                ['Integrante', 16], ['Reparto %', 11], ['Mínimo %', 10], ['Puntaje %', 10], ['Resultado', 12],
                ['Su parte Bs', 13, 'money'], ['A pagar Bs', 13, 'money'], ['Pagado el', 17], ['Motivo si Jime lo cerró', 40],
            ], $bonus->members->map(fn (array $member) => [
                $member['name'],
                (float) $member['share_pct'],
                (float) $compliance->get($member['user_id'])?->min_kpi_pct,
                Numbers::floorPercent($compliance->get($member['user_id'])?->partial_score_pct),
                self::RESULTS[$member['result']],
                Workbook::money($member['share']),
                Workbook::money($member['amount']),
                Workbook::dateTime($member['paid_at']),
                $member['closed_reason'],
            ]))
            ->sheet('Pagos', [
                ['Pedido', 10], ['Cliente', 28], ['Vendedor', 14], ['Forma', 13], ['Monto Bs', 13, 'money'],
                ['Estado', 12], ['Referencia', 18], ['Llegó el', 17], ['Revisado por', 14], ['Cuenta para el bono', 18],
            ], self::payments($week));

        if ($week->requires_system_deliverables) {
            $book->sheet('Entregas', [['Integrante', 16], ['Entrega', 60], ['Vence', 12], ['Fecha real', 12], ['Estado', 14]],
                SystemDeliverable::query()->with('member')->where('week_id', $week->getKey())->orderBy('sort_order')->get()
                    ->map(fn (SystemDeliverable $deliverable) => [
                        $deliverable->member->name,
                        $deliverable->title,
                        Workbook::date($deliverable->due_on),
                        Workbook::date($deliverable->delivered_on),
                        self::DELIVERABLE_STATUS[$deliverable->apiStatus()],
                    ]));
        }

        return $book;
    }

    private static function closing(BonusWeek $week, WeekBonusData $bonus): array
    {
        $closing = DB::table('weekly_closings as c')
            ->leftJoin('users as s', 's.id_user', '=', 'c.sent_by')
            ->leftJoin('users as a', 'a.id_user', '=', 'c.authorized_by')
            ->where('c.week_id', $week->getKey())
            ->first(['c.*', 's.name as sender', 'a.name as authorizer']);
        $view = WeeklyClosings::view($week);
        $payable = $bonus->shareOf('passes');

        return [
            ['Semana', "{$week->number} · {$week->label}"],
            ['Del', Workbook::date($week->starts_on)],
            ['Al', Workbook::date($week->ends_on)],
            ['Estado', self::STATUS[$bonus->status]],
            ['Meta Bs', Workbook::money($week->sales_target_bs)],
            ['Cobrado Bs', Workbook::money($bonus->collected)],
            ['Logro %', (float) $bonus->achievementPct],
            ['Escalón', self::TIERS[$bonus->tier]],
            ['Premio base Bs', Workbook::money($week->base_prize_bs)],
            ['Factor', (float) $bonus->releaseFactor],
            ['Fondo Bs', Workbook::money($bonus->fund)],
            ['A pagar Bs', Workbook::money($payable)],
            ['No distribuido Bs', Workbook::money($bonus->fund - $payable)],
            ['Cobros conciliados', $view['checks']['payments_reconciled']['detail']],
            ['KPI con evidencia', $view['checks']['kpi_evidence']['detail']],
            ['Duplicados', $view['checks']['duplicates_cleared']['detail']],
            ['Enviado por', $closing?->sender],
            ['Enviado el', Workbook::dateTime($closing?->sent_at)],
            ['Autorizado por', $closing?->authorizer],
            ['Autorizado el', Workbook::dateTime($closing?->authorized_at)],
            ['Motivo de devolución', $bonus->status === 'returned' ? $closing?->return_reason : null],
            ['Observaciones de Jime', $closing?->observations],
        ];
    }

    private static function indicators(BonusWeek $week): iterable
    {
        return DB::table('kpi_weekly_results as r')
            ->join('kpi_indicators as i', 'i.id_kpi_indicator', '=', 'r.indicator_id')
            ->join('users as u', 'u.id_user', '=', 'r.user_id')
            ->join('team_profiles as p', 'p.user_id', '=', 'r.user_id')
            ->where('r.week_id', $week->getKey())
            ->orderBy('p.sort_order')
            ->orderBy('i.sort_order')
            ->orderBy('i.name')
            ->get(['u.name as member', 'i.name', 'i.how_verified', 'r.target', 'r.actual', 'r.weight_pct'])
            ->map(function (object $row) {
                // Avance con tope de 100 %, como en el Excel de Jime.
                $progress = $row->actual === null ? null : min((float) $row->actual / (float) $row->target, 1);

                return [
                    $row->member,
                    $row->name,
                    $row->how_verified,
                    (float) $row->target,
                    $row->actual === null ? null : (float) $row->actual,
                    (float) $row->weight_pct,
                    $progress === null ? null : round($progress * 100, 2),
                    $progress === null ? null : round($progress * (float) $row->weight_pct, 2),
                ];
            });
    }

    private static function payments(BonusWeek $week): iterable
    {
        return DB::table('payments as p')
            ->join('orders as o', 'o.id_order', '=', 'p.order_id')
            ->leftJoin('users as s', 's.id_user', '=', 'o.seller_id')
            ->leftJoin('users as r', 'r.id_user', '=', 'p.reviewed_by')
            ->whereIn('p.status', array_keys(self::PAYMENT_STATUS))
            ->whereRaw(
                "(coalesce(p.received_at, p.submitted_at) at time zone 'America/La_Paz')::date between ? and ?",
                [$week->starts_on->toDateString(), $week->ends_on->toDateString()],
            )
            ->orderByRaw('coalesce(p.received_at, p.submitted_at)')
            ->get([
                'o.order_number', 'o.customer_name', 's.name as seller', 'p.method', 'p.amount_bs', 'p.status',
                'p.reference', 'p.received_at', 'p.submitted_at', 'r.name as reviewer',
            ])
            ->map(fn (object $row) => [
                $row->order_number,
                $row->customer_name,
                $row->seller ?? 'Tienda web',
                PaymentMethod::from($row->method)->label(),
                Workbook::money($row->amount_bs),
                self::PAYMENT_STATUS[$row->status],
                $row->reference,
                Workbook::dateTime($row->received_at ?? $row->submitted_at),
                $row->reviewer,
                $row->status === 'confirmed' ? 'Sí' : 'No',
            ]);
    }
}
