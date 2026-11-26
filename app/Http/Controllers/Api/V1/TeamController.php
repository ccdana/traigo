<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BonusWeek;
use App\Services\Team\MyWeek;
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

        return response()->json(['data' => MyWeek::of($request->user(), $week)]);
    }
}
