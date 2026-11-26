<?php

namespace App\Services\Management;

use App\Exceptions\ApiErrors;
use App\Exceptions\ApiException;
use App\Models\BonusWeek;
use App\Models\User;
use App\Services\Control\Compliance;
use App\Services\Control\WeekBonusData;
use App\Services\Control\WeeklyClosings;
use App\Support\Money;
use App\Support\Numbers;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * El bono de una semana que envió Jime. Dirección autoriza la foto; si después
 * del envío se devolvió algo y con eso cambia lo que se paga, no se puede
 * autorizar: se devuelve para que Jime lo envíe con lo cobrado al día.
 */
class WeeklyBonusAuthorization implements AuthorizationKind
{
    public function pending(): array
    {
        return $this->sent()
            ->get(['w.number', 'w.label', 'c.week_id', 'c.sent_at', 'u.name as sender'])
            ->map(fn (object $row) => $this->item($row))
            ->all();
    }

    public function detail(string $id): array
    {
        $row = $this->sent()->where('w.number', $this->number($id))
            ->first(['w.number', 'w.label', 'c.week_id', 'c.sent_at', 'c.observations', 'u.name as sender'])
            ?? throw Authorizations::gone();
        $week = BonusWeek::query()->findOrFail($row->week_id);
        $bonus = WeekBonusData::of($week);
        $compliance = Compliance::members($week)->keyBy('user_id');
        $change = $this->collectedChange($week);
        $blocked = $change['changes_payment']
            ? "Desde el envío cambió lo cobrado de la semana ({$change['text']}) y con eso lo que se paga. "
                .'Devuélvelo para que Jime lo envíe otra vez.'
            : null;

        return [
            ...$this->item($row),
            'pays' => $bonus->members->where('result', 'passes')
                ->map(fn (array $member) => ['name' => $member['name'], 'amount' => Money::format($member['share'])])
                ->values()->all(),
            'unpaid' => $bonus->members->where('result', '<>', 'passes')
                ->map(fn (array $member) => [
                    'name' => $member['name'],
                    'amount' => Money::format($member['share']),
                    'reason' => $this->unpaidReason($member, $compliance->get($member['user_id'])),
                ])
                ->values()->all(),
            'checks' => array_values(array_filter([
                'Cobros conciliados: '.WeeklyClosings::paymentsCheck($week)['detail'],
                'KPI con evidencia revisada',
                'Duplicados depurados',
                filled($row->observations) ? "Observaciones de Jime: {$row->observations}" : null,
                $change['text'] !== null && ! $change['changes_payment']
                    ? "Desde el envío cambió lo cobrado ({$change['text']}); lo que se paga no cambia."
                    : null,
            ])),
            'changes' => [],
            'can_approve' => $blocked === null,
            'blocked_reason' => $blocked,
        ];
    }

    public function approve(User $direction, string $id): void
    {
        DB::transaction(function () use ($direction, $id) {
            $closing = $this->lockSent($id);

            if ($closing->sent_by === $direction->getKey()) {
                throw ApiException::conflict('own_record', 'Nadie autoriza lo que envió.');
            }

            $week = BonusWeek::query()->findOrFail($closing->week_id);
            $change = $this->collectedChange($week);

            if ($change['changes_payment']) {
                throw ApiException::conflict(
                    'collected_changed',
                    "Desde el envío cambió lo cobrado ({$change['text']}) y con eso lo que se paga. Devuélvelo a Jime.",
                );
            }

            try {
                DB::table('weekly_closings')->where('week_id', $closing->week_id)->update([
                    'status' => 'authorized',
                    'direction_approved' => true,
                    'authorized_by' => $direction->getKey(),
                    'authorized_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (QueryException $e) {
                // La base impide pagar si falta algo del «LISTO PARA PAGO».
                if ($e->getCode() === '23514') {
                    throw ApiException::conflict('pending_members', ApiErrors::ruleMessage($e));
                }

                throw $e;
            }
        });
    }

    public function sendBack(User $direction, string $id, string $reason): void
    {
        DB::transaction(function () use ($id, $reason) {
            $closing = $this->lockSent($id);

            DB::table('weekly_closings')->where('week_id', $closing->week_id)->update([
                'status' => 'returned',
                'return_reason' => $reason,
                'direction_approved' => false,
                'authorized_by' => null,
                'authorized_at' => null,
                'updated_at' => now(),
            ]);
            // Los cierres a mano se indican otra vez al reenviar, y mientras
            // tanto esas personas pueden completar lo suyo.
            DB::table('weekly_member_results')->where('week_id', $closing->week_id)->delete();
        });
    }

    /**
     * Lo cobrado de la semana ahora contra la foto enviada.
     *
     * @return array{text: ?string, changes_payment: bool}
     */
    private function collectedChange(BonusWeek $week): array
    {
        $saved = DB::table('weekly_closings')->where('week_id', $week->getKey())->value('collected_bs');
        $live = DB::table('v_week_collected')->where('week_id', $week->getKey())->value('collected_bs');

        if (Money::toCents($saved) === Money::toCents($live)) {
            return ['text' => null, 'changes_payment' => false];
        }

        $amounts = fn (string $table) => DB::table($table)
            ->where('week_id', $week->getKey())
            ->pluck('amount_bs', 'user_id')
            ->map(fn ($amount) => Money::toCents($amount ?? '0'))
            ->sortKeys()
            ->all();

        return [
            'text' => Money::display(Money::toCents($saved)).' → '.Money::display(Money::toCents($live)),
            'changes_payment' => $amounts('weekly_member_results') !== $amounts('v_week_member_bonus'),
        ];
    }

    private function unpaidReason(array $member, ?object $row): string
    {
        if ($member['closed_reason'] !== null) {
            return "Cerrado por Jime: {$member['closed_reason']}";
        }

        if ($row === null || $member['result'] === 'pending') {
            return 'Pendiente';
        }

        if ($row->deliverables_total !== null && $row->deliverables_on_time < $row->deliverables_total) {
            return "{$row->deliverables_on_time} de {$row->deliverables_total} entregas a tiempo";
        }

        return 'KPI '.Numbers::floorPercent($row->partial_score_pct).' % de '.Numbers::floorPercent($row->min_kpi_pct).' %';
    }

    private function item(object $row): array
    {
        $payable = DB::table('weekly_member_results')
            ->where('week_id', $row->week_id)
            ->where('result', 'passes')
            ->pluck('amount_bs')
            ->sum(fn (string $amount) => Money::toCents($amount));

        return Authorizations::item(
            'weekly_bonus',
            (string) $row->number,
            "Bono semana {$row->label}",
            $row->sender,
            $row->sent_at,
            Money::format($payable),
        );
    }

    private function sent()
    {
        return DB::table('weekly_closings as c')
            ->join('bonus_weeks as w', 'w.id_bonus_week', '=', 'c.week_id')
            ->join('users as u', 'u.id_user', '=', 'c.sent_by')
            ->where('c.status', 'sent')
            ->orderBy('w.number');
    }

    /** Bloqueado hasta terminar: ninguna devolución cambia lo cobrado en medio. */
    private function lockSent(string $id): object
    {
        $weekId = DB::table('bonus_weeks')->where('number', $this->number($id))->value('id_bonus_week')
            ?? throw Authorizations::gone();
        $closing = DB::table('weekly_closings')->where('week_id', $weekId)->lockForUpdate()->first();

        if ($closing?->status !== 'sent') {
            throw Authorizations::gone();
        }

        return $closing;
    }

    private function number(string $id): int
    {
        if (! ctype_digit($id) || strlen($id) > 3) {
            throw Authorizations::gone();
        }

        return (int) $id;
    }
}
