<?php

namespace App\Services\Team;

use App\Models\BonusWeek;
use App\Models\KpiEvidence;
use App\Models\SystemDeliverable;
use App\Models\User;
use App\Support\Numbers;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * El KPI de una persona en una semana. El puntaje y si cumple salen de las
 * vistas de la base (las fórmulas del Excel); aquí no se recalculan.
 */
final class MemberKpi
{
    private function __construct(
        public readonly User $member,
        public readonly BonusWeek $week,
        /** Fila de `v_member_week_compliance`. */
        public readonly object $result,
    ) {}

    public static function of(User $member, BonusWeek $week): self
    {
        $result = DB::table('v_member_week_compliance')
            ->where('week_id', $week->getKey())
            ->where('user_id', $member->getKey())
            ->first()
            // Sin parte en el bono (no debería pasar con el equipo).
            ?? (object) [
                'share_pct' => '0', 'min_kpi_pct' => '0', 'score_pct' => null, 'partial_score_pct' => '0',
                'result' => 'pending', 'closed_reason' => null,
                'deliverables_total' => null, 'deliverables_on_time' => null,
            ];

        return new self($member, $week, $result);
    }

    public function shareRate(): string
    {
        return Numbers::rate($this->result->share_pct);
    }

    public function minimumPercent(): int
    {
        return Numbers::floorPercent($this->result->min_kpi_pct);
    }

    /** El avance con lo cargado hasta ahora (nunca nulo). */
    public function partialScorePercent(): int
    {
        return Numbers::floorPercent($this->result->partial_score_pct);
    }

    /** Nulo mientras falte cargar algún indicador. */
    public function scorePercent(): ?int
    {
        return $this->result->score_pct === null
            ? null
            : Numbers::floorPercent($this->result->score_pct);
    }

    /** Las entregas, solo en la semana que las pide y a quien las tiene. */
    public function deliverableProgress(): ?array
    {
        if (! $this->week->requires_system_deliverables || $this->result->deliverables_total === null) {
            return null;
        }

        return [
            'approved' => (int) $this->result->deliverables_on_time,
            'total' => (int) $this->result->deliverables_total,
        ];
    }

    /** @return Collection<int, SystemDeliverable> */
    public function deliverables(): Collection
    {
        if (! $this->week->requires_system_deliverables) {
            return collect();
        }

        return SystemDeliverable::query()
            ->with('member')
            ->where('week_id', $this->week->getKey())
            ->where('user_id', $this->member->getKey())
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Los indicadores con su evidencia, de lo más nuevo a lo más antiguo. El
     * estado del indicador es el de la evidencia más reciente.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function indicators(): Collection
    {
        $rows = DB::table('kpi_weekly_results as r')
            ->join('kpi_indicators as i', 'i.id_kpi_indicator', '=', 'r.indicator_id')
            ->where('r.week_id', $this->week->getKey())
            ->where('r.user_id', $this->member->getKey())
            ->orderBy('i.sort_order')
            ->orderBy('i.name')
            ->get([
                'r.id_kpi_weekly_result as id', 'i.name', 'i.how_verified', 'i.unit',
                'r.target', 'r.actual', 'r.weight_pct',
            ]);

        $evidence = KpiEvidence::query()
            ->with('order')
            ->whereIn('kpi_weekly_result_id', $rows->pluck('id'))
            ->orderByDesc('submitted_at')
            ->orderByDesc('id_kpi_evidence')
            ->get()
            ->groupBy('kpi_weekly_result_id');

        return $rows->map(function (object $row) use ($evidence): array {
            $mine = $evidence->get($row->id, collect());

            return [
                'id' => $row->id,
                'name' => $row->name,
                'how_verified' => $row->how_verified,
                'unit' => $row->unit,
                'target' => Numbers::plain($row->target),
                'actual' => $row->actual === null ? null : Numbers::plain($row->actual),
                'weight' => Numbers::rate($row->weight_pct),
                'evidence_status' => $mine->first()?->status ?? 'missing',
                'evidence' => $mine->map->toApi()->values(),
            ];
        });
    }

    /**
     * Si todavía se puede subir evidencia: no cuando Dirección ya autorizó la
     * semana ni cuando Jime cerró la de esta persona.
     */
    public static function acceptsEvidence(string $userId, string $weekId): bool
    {
        $authorized = DB::table('weekly_closings')
            ->where('week_id', $weekId)
            ->whereIn('status', ['authorized', 'paid'])
            ->exists();

        return ! $authorized && ! DB::table('weekly_member_results')
            ->where('week_id', $weekId)
            ->where('user_id', $userId)
            ->whereNotNull('closed_by')
            ->exists();
    }
}
