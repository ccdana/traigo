<?php

namespace App\Services\Control;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Models\BonusWeek;
use App\Models\User;
use App\Services\Team\WeekLock;
use App\Support\Dates;
use App\Support\Lists;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * El cierre de la semana de Jime: los 4 controles del Excel y el envío a
 * Dirección con la foto del reparto. A quienes sigan pendientes se les puede
 * cerrar como «no cumple», con un motivo. La base repite las reglas que
 * protegen el pago.
 */
class WeeklyClosings
{
    /** @return array<string, mixed> */
    public static function view(BonusWeek $week): array
    {
        $closing = DB::table('weekly_closings')->where('week_id', $week->getKey())->first();
        $status = $closing->status ?? 'open';
        $editable = ! in_array($status, WeekLock::FROZEN, true);
        $authorized = in_array($status, ['authorized', 'paid'], true);

        return [
            'week' => ['number' => $week->number, ...$week->toApi()],
            'current_week' => BonusWeek::current()->number,
            // Se envía cuando la semana terminó, para que no quede nada fuera.
            'sendable_from' => self::sendableFrom($week)->toDateString(),
            'status' => $status,
            'checks' => [
                'payments_reconciled' => self::paymentsCheck($week),
                'kpi_evidence' => self::evidenceCheck($week),
                'duplicates_cleared' => self::duplicatesCheck($week, ! $editable),
                'direction_approval' => [
                    'ok' => $authorized,
                    'detail' => $authorized
                        ? 'Autorizado el '.Dates::short(Carbon::parse($closing->authorized_at)->timezone(config('app.timezone')))
                        : 'Falta su aprobación',
                ],
            ],
            'pending_members' => $editable
                ? self::pendingMembers($week)->map(fn (object $row) => [
                    'id' => $row->user_id,
                    'name' => $row->name,
                    'pending_reason' => Compliance::pendingReason($row),
                ])->values()->all()
                : [],
            'observations' => $closing->observations ?? null,
            'return_reason' => $status === 'returned' ? $closing->return_reason : null,
        ];
    }

    /** @param  array<string, string>  $closePending  id de integrante → motivo */
    public function send(
        User $jime,
        BonusWeek $week,
        bool $duplicatesCleared,
        array $closePending,
        ?string $observations,
    ): void {
        $from = self::sendableFrom($week);

        if (today()->lt($from)) {
            throw ApiException::conflict(
                'week_not_ended',
                'El cierre se envía cuando termina la semana (desde el '.Dates::short($from).').',
            );
        }

        DB::transaction(function () use ($jime, $week, $duplicatesCleared, $closePending, $observations) {
            DB::table('weekly_closings')->insertOrIgnore(['week_id' => $week->getKey()]);
            // Mientras se envía, nadie cambia los datos de la semana.
            $status = DB::table('weekly_closings')->where('week_id', $week->getKey())->lockForUpdate()->value('status');

            if (in_array($status, WeekLock::FROZEN, true)) {
                throw ApiException::conflict('closing_already_sent', 'El cierre de la semana ya se envió.');
            }

            $payments = self::paymentsCheck($week);
            $evidence = self::evidenceCheck($week);

            if (! $payments['ok'] || ! $evidence['ok'] || ! $duplicatesCleared) {
                throw ApiException::invalid(match (true) {
                    ! $payments['ok'] => $payments['detail'],
                    ! $evidence['ok'] => $evidence['detail'],
                    default => 'Confirma que no quedan duplicados.',
                }, 'checks_failed');
            }

            // Al reenviar, los cierres a mano se indican otra vez.
            DB::table('weekly_member_results')->where('week_id', $week->getKey())->delete();
            $this->saveResults($jime, $week, $closePending);

            $preview = DB::table('v_week_bonus')->where('week_id', $week->getKey())->first();
            DB::table('weekly_closings')->where('week_id', $week->getKey())->update([
                'status' => 'sent',
                'payments_reconciled' => true,
                'kpi_evidence_ok' => true,
                'duplicates_cleared' => true,
                'direction_approved' => false,
                'observations' => filled($observations) ? trim($observations) : null,
                'collected_bs' => $preview->collected_bs,
                'achievement_pct' => $preview->achievement_pct,
                'tier' => $preview->tier,
                'base_prize_bs' => $preview->base_prize_bs,
                'release_factor' => $preview->release_factor,
                'fund_bs' => $preview->fund_bs,
                'sent_by' => $jime->getKey(),
                'sent_at' => now(),
                'authorized_by' => null,
                'authorized_at' => null,
                'return_reason' => null,
                'updated_at' => now(),
            ]);
        });
    }

    /** La foto: una fila por integrante, con los cierres a mano. */
    private function saveResults(User $jime, BonusWeek $week, array $closePending): void
    {
        $pending = self::pendingMembers($week);

        foreach ($closePending as $memberId => $reason) {
            if (! $pending->contains('user_id', $memberId)) {
                throw ApiException::invalid('Solo se cierra a mano a quien está pendiente.', 'not_pending');
            }

            if (blank($reason)) {
                throw ApiException::invalid('Escribe el motivo de cada cierre.');
            }
        }

        $unresolved = $pending->reject(fn (object $row) => isset($closePending[$row->user_id]))->pluck('name')->all();

        if ($unresolved !== []) {
            throw ApiException::conflict(
                'pending_members',
                Lists::join($unresolved).(count($unresolved) === 1 ? ' sigue pendiente.' : ' siguen pendientes.'),
            );
        }

        $rows = DB::table('v_week_member_bonus')->where('week_id', $week->getKey())->get();

        DB::table('weekly_member_results')->insert($rows->map(function (object $row) use ($jime, $week, $closePending) {
            $closed = isset($closePending[$row->user_id]);

            return [
                'week_id' => $week->getKey(),
                'user_id' => $row->user_id,
                'share_pct' => $row->share_pct,
                'min_kpi_pct' => $row->min_kpi_pct,
                'score_pct' => $row->score_pct,
                'result' => $closed ? 'fails' : $row->result,
                'amount_bs' => $closed ? '0' : ($row->amount_bs ?? '0'),
                'closed_by' => $closed ? $jime->getKey() : null,
                'closed_at' => $closed ? now() : null,
                'close_reason' => $closed ? trim($closePending[$row->user_id]) : null,
            ];
        })->all());
    }

    /**
     * Pendientes según los datos cargados (los mismos que la base deja cerrar
     * a mano).
     */
    private static function pendingMembers(BonusWeek $week): Collection
    {
        $pending = DB::table('v_member_week_compliance_raw')
            ->where('week_id', $week->getKey())
            ->where('result', 'pending')
            ->pluck('user_id');

        return Compliance::members($week)->whereIn('user_id', $pending->all())->values();
    }

    /** @return array{ok: bool, detail: string} */
    public static function paymentsCheck(BonusWeek $week): array
    {
        $counts = DB::table('payments as p')
            ->join('orders as o', 'o.id_order', '=', 'p.order_id')
            ->whereIn('p.status', [PaymentStatus::Submitted->value, PaymentStatus::Confirmed->value])
            ->whereNotIn('o.status', ['cancelled', 'returned', 'expired'])
            ->whereRaw(
                "(coalesce(p.received_at, p.submitted_at) at time zone 'America/La_Paz')::date between ? and ?",
                [$week->starts_on->toDateString(), $week->ends_on->toDateString()],
            )
            ->selectRaw(
                "count(*) filter (where p.status = 'confirmed') as confirmed,
                 count(*) filter (where p.status = 'submitted' and p.method <> ?) as proofs,
                 count(*) filter (where p.status = 'submitted' and p.method = ?) as cash",
                [PaymentMethod::Cash->value, PaymentMethod::Cash->value],
            )
            ->first();

        $missing = array_values(array_filter([
            $counts->proofs > 0
                ? "{$counts->proofs} ".($counts->proofs === 1 ? 'comprobante' : 'comprobantes').' por revisar'
                : null,
            $counts->cash > 0 ? "{$counts->cash} en efectivo sin caja validada" : null,
        ]));

        return [
            'ok' => $missing === [],
            'detail' => $missing === []
                ? "{$counts->confirmed} de {$counts->confirmed} pagos confirmados"
                : (($counts->proofs + $counts->cash) === 1 ? 'Falta ' : 'Faltan ').Lists::join($missing),
        ];
    }

    /** @return array{ok: bool, detail: string} */
    private static function evidenceCheck(BonusWeek $week): array
    {
        $waiting = DB::table('kpi_evidence as e')
            ->join('kpi_weekly_results as r', 'r.id_kpi_weekly_result', '=', 'e.kpi_weekly_result_id')
            ->join('users as u', 'u.id_user', '=', 'r.user_id')
            ->join('team_profiles as p', 'p.user_id', '=', 'r.user_id')
            ->where('r.week_id', $week->getKey())
            ->where('e.status', 'in_review')
            ->orderBy('p.sort_order')
            ->distinct()
            ->get(['u.name', 'p.sort_order'])
            ->pluck('name')
            ->all();
        $members = DB::table('team_profiles')->count();

        return [
            'ok' => $waiting === [],
            'detail' => $waiting === []
                ? "{$members} de {$members} personas sin evidencia por revisar"
                : 'Falta revisar la evidencia de '.Lists::join($waiting),
        ];
    }

    /**
     * Jime confirma al enviar que no quedan duplicados; el detalle muestra los
     * que quitó esa semana.
     *
     * @return array{ok: bool, detail: string}
     */
    private static function duplicatesCheck(BonusWeek $week, bool $confirmed): array
    {
        $removed = DB::table('orders')
            ->whereNotNull('duplicate_of_id')
            ->whereRaw(
                "(cancelled_at at time zone 'America/La_Paz')::date between ? and ?",
                [$week->starts_on->toDateString(), $week->ends_on->toDateString()],
            )
            ->orderBy('cancelled_at')
            ->pluck('order_number')
            ->all();

        return [
            'ok' => $confirmed,
            'detail' => match (count($removed)) {
                0 => 'Ningún duplicado quitado esta semana',
                1 => "1 duplicado quitado ({$removed[0]})",
                default => count($removed).' duplicados quitados ('.implode(', ', $removed).')',
            },
        ];
    }

    private static function sendableFrom(BonusWeek $week): Carbon
    {
        return Carbon::parse($week->ends_on->toDateString(), config('app.timezone'))->addDay();
    }
}
