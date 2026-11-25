<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BonusWeek;
use App\Services\Team\MemberKpi;
use App\Services\Team\TeamHome;
use App\Services\Team\TeamWeek;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Inicio, «Equipo» y «Mi semana»: cada quien ve lo suyo. */
class TeamController extends Controller
{
    public function home(Request $request): JsonResponse
    {
        return response()->json(['data' => TeamHome::of($request->user())]);
    }

    public function teamWeek(Request $request): JsonResponse
    {
        return response()->json(['data' => TeamWeek::of($request->user())]);
    }

    public function myWeek(Request $request): JsonResponse
    {
        $data = $request->validate(['week' => ['nullable', 'integer', 'min:1']]);
        $week = isset($data['week']) ? BonusWeek::started((int) $data['week']) : BonusWeek::current();
        $member = $request->user();
        $kpi = MemberKpi::of($member, $week);

        return response()->json([
            'data' => [
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
            ],
        ]);
    }
}
