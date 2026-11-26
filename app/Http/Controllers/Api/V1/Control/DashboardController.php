<?php

namespace App\Http\Controllers\Api\V1\Control;

use App\Http\Controllers\Controller;
use App\Models\BonusWeek;
use App\Services\Control\Compliance;
use App\Services\Control\ControlSummary;
use App\Services\Control\PendingInbox;
use App\Services\Control\Weeks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** El resumen, la bandeja de pendientes y el cumplimiento. */
class DashboardController extends Controller
{
    public function summary(): JsonResponse
    {
        return response()->json(['data' => ControlSummary::of()]);
    }

    public function pending(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => ['nullable', Rule::in(PendingInbox::TYPES)]]);

        return response()->json(['data' => PendingInbox::items($data['type'] ?? null)]);
    }

    public function compliance(Request $request): JsonResponse
    {
        $data = $request->validate(['week' => ['nullable', 'integer', 'min:1']]);
        $week = Weeks::forClosing(isset($data['week']) ? (int) $data['week'] : null);

        return response()->json([
            'data' => Compliance::of($week),
            'meta' => [
                'week' => ['number' => $week->number, ...$week->toApi()],
                'current_week' => BonusWeek::current()->number,
            ],
        ]);
    }
}
