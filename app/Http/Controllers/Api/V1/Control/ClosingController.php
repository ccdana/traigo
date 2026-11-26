<?php

namespace App\Http\Controllers\Api\V1\Control;

use App\Http\Controllers\Controller;
use App\Models\BonusWeek;
use App\Services\Control\Bonuses;
use App\Services\Control\WeeklyClosings;
use App\Services\Control\Weeks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** El cierre semanal y el bono. */
class ClosingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return $this->closing(Weeks::forClosing($this->requestedWeek($request)));
    }

    public function send(Request $request, int $week, WeeklyClosings $closings): JsonResponse
    {
        $data = $request->validate([
            'duplicates_cleared' => ['required', 'boolean'],
            'close_pending' => ['present', 'array', 'max:20'],
            'close_pending.*.member_id' => ['required', 'uuid', 'distinct'],
            'close_pending.*.reason' => ['nullable', 'string', 'max:500'],
            'observations' => ['nullable', 'string', 'max:1000'],
        ]);

        $bonusWeek = BonusWeek::started($week);
        $closings->send(
            $request->user(),
            $bonusWeek,
            $request->boolean('duplicates_cleared'),
            array_column($data['close_pending'], 'reason', 'member_id'),
            $data['observations'] ?? null,
        );

        return $this->closing($bonusWeek);
    }

    public function bonus(Request $request): JsonResponse
    {
        return $this->bonusOf(Weeks::forBonus($this->requestedWeek($request)));
    }

    public function paid(int $week, string $member, Bonuses $bonuses): JsonResponse
    {
        $bonusWeek = BonusWeek::started($week);
        $bonuses->markPaid($bonusWeek, $member);

        return $this->bonusOf($bonusWeek);
    }

    private function requestedWeek(Request $request): ?int
    {
        $data = $request->validate(['week' => ['nullable', 'integer', 'min:1']]);

        return isset($data['week']) ? (int) $data['week'] : null;
    }

    private function closing(BonusWeek $week): JsonResponse
    {
        return response()->json(['data' => WeeklyClosings::view($week)]);
    }

    private function bonusOf(BonusWeek $week): JsonResponse
    {
        return response()->json(['data' => Bonuses::view($week)]);
    }
}
