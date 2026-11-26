<?php

namespace App\Services\Control;

use App\Models\BonusWeek;
use App\Support\Dates;
use App\Support\Money;
use App\Support\Numbers;

/** El resumen de Jime y Dirección: la semana en curso y lo que falta revisar. */
final class ControlSummary
{
    /** @return array<string, mixed> */
    public static function of(): array
    {
        $week = BonusWeek::current();
        $bonus = WeekBonusData::of($week);

        return [
            'as_of' => Dates::toApi(now()),
            'week' => $week->toApi(),
            'collected' => Money::fromDatabase($bonus->collected),
            'pending' => PendingInbox::counts(),
            'members' => Compliance::members($week)->map(fn (object $row) => [
                'id' => $row->user_id,
                'name' => $row->name,
                'score_percent' => Numbers::floorPercent($row->partial_score_pct),
                'minimum_percent' => Numbers::floorPercent($row->min_kpi_pct),
                'compliance' => $row->result,
                'fund_share_rate' => Numbers::rate($row->share_pct),
                'note' => self::note($row),
            ])->values(),
            'bonus' => [
                'level' => $bonus->tier,
                'prize' => Money::format($bonus->fund),
                'payable' => Money::format($bonus->shareOf('passes')),
                'unpaid_failing' => Money::format($bonus->shareOf('fails')),
                'unpaid_pending' => Money::format($bonus->shareOf('pending')),
            ],
        ];
    }

    private static function note(object $row): ?string
    {
        if ($row->closed_reason !== null) {
            return "Cerrado por Jime: {$row->closed_reason}";
        }

        if ($row->deliverables_total !== null && $row->deliverables_on_time < $row->deliverables_total) {
            return "{$row->deliverables_on_time} de {$row->deliverables_total} entregas validadas";
        }

        return null;
    }
}
