<?php

namespace App\Services\Team;

use App\Models\BonusWeek;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/** «Equipo»: la meta de la semana, los escalones y la campaña. */
final class TeamWeek
{
    /** @return array<string, mixed> */
    public static function of(User $member): array
    {
        $week = BonusWeek::current();
        $kpi = MemberKpi::of($member, $week);

        return [
            'week' => $week->toApi(),
            'collected' => $week->collected(),
            'tiers' => self::tiers($week, $kpi->result->share_pct),
            'me' => [
                'fund_share_rate' => $kpi->shareRate(),
                'minimum_percent' => $kpi->minimumPercent(),
                'partial_score_percent' => $kpi->partialScorePercent(),
                'compliance' => $kpi->result->result,
            ],
            'weeks' => BonusWeek::query()->orderBy('number')->get()->map(fn (BonusWeek $other) => [
                'number' => $other->number,
                ...$other->toApi(),
                'is_current' => $other->getKey() === $week->getKey(),
            ])->values(),
        ];
    }

    /**
     * Los escalones del premio: ventas necesarias (hacia arriba), fondo y la
     * parte de quien pregunta. Los calcula la base, sin errores de decimales.
     *
     * @return list<array<string, mixed>>
     */
    private static function tiers(BonusWeek $week, string $sharePct): array
    {
        $rows = DB::select(<<<'SQL'
            select t.tier,
                   t.threshold_pct,
                   (ceil(w.sales_target_bs * t.threshold_pct) / 100)::numeric(12, 2) as sales_needed,
                   round(w.base_prize_bs * t.release_factor, 2) as fund,
                   round(round(w.base_prize_bs * t.release_factor, 2) * ?::numeric / 100, 2) as my_share
            from bonus_tiers t
            cross join bonus_weeks w
            where w.id_bonus_week = ?
            order by t.threshold_pct
            SQL, [$sharePct, $week->getKey()]);

        return array_map(fn (object $row) => [
            'tier' => $row->tier,
            'threshold_percent' => (int) $row->threshold_pct,
            'sales_needed' => Money::fromDatabase($row->sales_needed),
            'fund' => Money::fromDatabase($row->fund),
            'my_share' => Money::fromDatabase($row->my_share),
        ], $rows);
    }
}
