<?php

namespace App\Http\Controllers\Api\V1\Control;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Control\KpiReviews;
use App\Services\Control\Weeks;
use App\Services\Team\MyWeek;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** La evidencia y los valores reales del KPI. */
class KpiController extends Controller
{
    public function show(string $result): JsonResponse
    {
        return $this->respond($result);
    }

    public function approve(Request $request, string $result, KpiReviews $reviews): JsonResponse
    {
        $data = $request->validate([
            'actual' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'actual.required' => 'Escribe el valor real.',
            'actual.min' => 'El valor real no puede ser negativo.',
        ]);

        $reviews->approve($request->user(), $result, (float) $data['actual'], $data['note'] ?? null);

        return $this->respond($result);
    }

    public function observe(Request $request, string $result, KpiReviews $reviews): JsonResponse
    {
        $data = $request->validate(
            ['note' => ['required', 'string', 'max:500']],
            ['note.required' => 'Escribe qué falta.'],
        );

        $reviews->observe($request->user(), $result, $data['note']);

        return $this->respond($result);
    }

    /** La semana de un integrante, como la ve esa persona en «Mi semana». */
    public function memberWeek(Request $request, string $member): JsonResponse
    {
        $data = $request->validate(['week' => ['nullable', 'integer', 'min:1']]);
        $user = User::query()
            ->whereIn('id_user', DB::table('team_profiles')->select('user_id'))
            ->find($member)
            ?? throw ApiException::notFound('No encontramos a esa persona.');
        $week = Weeks::forClosing(isset($data['week']) ? (int) $data['week'] : null);

        return response()->json(['data' => MyWeek::of($user, $week)]);
    }

    private function respond(string $result): JsonResponse
    {
        return response()->json(['data' => KpiReviews::view($result)]);
    }
}
