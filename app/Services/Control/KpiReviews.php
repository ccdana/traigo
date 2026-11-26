<?php

namespace App\Services\Control;

use App\Exceptions\ApiException;
use App\Models\BonusWeek;
use App\Models\User;
use App\Services\Team\MemberKpi;
use App\Services\Team\WeekLock;
use Illuminate\Support\Facades\DB;

/**
 * Jime revisa la evidencia de un indicador y carga su valor real, aunque nadie
 * haya subido nada. De ahí salen el puntaje y el «cumple».
 */
class KpiReviews
{
    /** @return array<string, mixed> */
    public static function view(string $resultId): array
    {
        $result = DB::table('kpi_weekly_results')->where('id_kpi_weekly_result', $resultId)->first()
            ?? throw self::missing();
        $member = User::query()->findOrFail($result->user_id);
        $week = BonusWeek::query()->findOrFail($result->week_id);
        $indicator = MemberKpi::of($member, $week)->indicators()->firstWhere('id', $resultId);

        return [
            'result_id' => $resultId,
            'member' => ['id' => $member->getKey(), 'name' => $member->name],
            'week_label' => $week->label,
            'indicator' => collect($indicator)->except('evidence')->all(),
            'evidence' => $indicator['evidence'],
        ];
    }

    /** Carga el valor real y valida la evidencia que esperaba. */
    public function approve(User $jime, string $resultId, float $actual, ?string $note): void
    {
        DB::transaction(function () use ($jime, $resultId, $actual, $note) {
            $result = $this->lockEditable($jime, $resultId);

            DB::table('kpi_weekly_results')->where('id_kpi_weekly_result', $result->id_kpi_weekly_result)->update([
                'actual' => number_format($actual, 2, '.', ''),
                'loaded_by' => $jime->getKey(),
                'loaded_at' => now(),
                'note' => filled($note) ? trim($note) : null,
                'updated_at' => now(),
            ]);
            $this->reviewEvidence($jime, $resultId, 'validated', null);
        });
    }

    /** Pide otra evidencia, explicando qué falta. */
    public function observe(User $jime, string $resultId, string $note): void
    {
        DB::transaction(function () use ($jime, $resultId, $note) {
            $this->lockEditable($jime, $resultId);

            if ($this->reviewEvidence($jime, $resultId, 'observed', $note) === 0) {
                throw ApiException::conflict('already_reviewed', 'No hay evidencia esperando revisión.');
            }
        });
    }

    private function lockEditable(User $jime, string $resultId): object
    {
        $result = DB::table('kpi_weekly_results')
            ->where('id_kpi_weekly_result', $resultId)
            ->lockForUpdate()
            ->first()
            ?? throw self::missing();

        if ($result->user_id === $jime->getKey()) {
            throw ApiException::conflict('own_record', 'Nadie revisa su propio KPI.');
        }

        if (BonusWeek::query()->findOrFail($result->week_id)->starts_on->gt(today())) {
            throw ApiException::invalid('Esa semana todavía no empieza.');
        }

        WeekLock::ensureOpen($result->week_id);

        return $result;
    }

    private function reviewEvidence(User $jime, string $resultId, string $status, ?string $note): int
    {
        return DB::table('kpi_evidence')
            ->where('kpi_weekly_result_id', $resultId)
            ->where('status', 'in_review')
            ->update([
                'status' => $status,
                'review_note' => $note,
                'reviewed_by' => $jime->getKey(),
                'reviewed_at' => now(),
            ]);
    }

    private static function missing(): ApiException
    {
        return ApiException::notFound('No encontramos ese indicador.');
    }
}
