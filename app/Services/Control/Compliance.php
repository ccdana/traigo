<?php

namespace App\Services\Control;

use App\Models\BonusWeek;
use App\Support\Numbers;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** El cumplimiento de cada integrante en una semana, de las vistas de la base. */
final class Compliance
{
    /**
     * En el orden de la app. `deliverables_*` solo en la semana que pide
     * entregas.
     *
     * @return Collection<int, object>
     */
    public static function members(BonusWeek $week): Collection
    {
        return DB::table('v_member_week_compliance as c')
            ->join('users as u', 'u.id_user', '=', 'c.user_id')
            ->leftJoin('team_profiles as p', 'p.user_id', '=', 'c.user_id')
            ->leftJoin('v_deliverables_member as d', function ($join) {
                $join->on('d.week_id', '=', 'c.week_id')->on('d.user_id', '=', 'c.user_id');
            })
            ->where('c.week_id', $week->getKey())
            ->orderBy('p.sort_order')
            ->get([
                'c.user_id', 'u.name', 'u.title', 'c.share_pct', 'c.min_kpi_pct', 'c.indicators_total',
                'c.indicators_loaded', 'c.partial_score_pct', 'c.score_pct', 'c.deliverables_total',
                'c.deliverables_on_time', 'c.result', 'c.closed_reason', 'd.pending as deliverables_pending',
            ])
            ->when(! $week->requires_system_deliverables, fn (Collection $rows) => $rows->each(function (object $row) {
                $row->deliverables_total = null;
                $row->deliverables_on_time = null;
                $row->deliverables_pending = null;
            }));
    }

    /** @return list<array<string, mixed>> */
    public static function of(BonusWeek $week): array
    {
        return self::members($week)->map(fn (object $row) => [
            'id' => $row->user_id,
            'name' => $row->name,
            'title' => $row->title,
            'score_percent' => $row->score_pct === null ? null : Numbers::floorPercent($row->score_pct),
            'partial_score_percent' => Numbers::floorPercent($row->partial_score_pct),
            'minimum_percent' => Numbers::floorPercent($row->min_kpi_pct),
            'compliance' => $row->result,
            'indicators_loaded' => $row->indicators_loaded,
            'indicators_total' => $row->indicators_total,
            'deliverables_on_time' => $row->deliverables_on_time,
            'deliverables_total' => $row->deliverables_total,
            'closed_reason' => $row->closed_reason,
        ])->values()->all();
    }

    /** Qué le falta a quien sigue pendiente. */
    public static function pendingReason(object $row): string
    {
        if ($row->deliverables_pending > 0) {
            return "{$row->deliverables_on_time} de {$row->deliverables_total} entregas validadas";
        }

        $missing = $row->indicators_total - $row->indicators_loaded;

        return $missing === 1 ? 'Falta 1 valor real' : "Faltan {$missing} valores reales";
    }
}
