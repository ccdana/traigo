<?php

namespace App\Services\Team;

use App\Models\BonusWeek;
use App\Models\User;

/**
 * La semana de un integrante: su puntaje, indicadores, evidencia y entregas.
 * La ve cada quien («Mi semana») y, para cargar valores, también Jime.
 */
final class MyWeek
{
    /** @return array<string, mixed> */
    public static function of(User $member, BonusWeek $week): array
    {
        $kpi = MemberKpi::of($member, $week);

        return [
            'week' => ['number' => $week->number, ...$week->toApi()],
            'count_target_factor' => number_format((float) $week->count_target_factor, 2, '.', ''),
            'score_percent' => $kpi->scorePercent(),
            'partial_score_percent' => $kpi->partialScorePercent(),
            'minimum_percent' => $kpi->minimumPercent(),
            'compliance' => $kpi->result->result,
            'indicators' => $kpi->indicators()->values(),
            'deliverables' => $kpi->deliverables()->map->toApi()->values(),
            'accepts_evidence' => MemberKpi::acceptsEvidence($member->getKey(), $week->getKey()),
            'closed_reason' => $kpi->result->closed_reason,
        ];
    }
}
