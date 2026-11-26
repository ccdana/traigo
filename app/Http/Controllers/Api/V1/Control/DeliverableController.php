<?php

namespace App\Http\Controllers\Api\V1\Control;

use App\Http\Controllers\Controller;
use App\Services\Control\DeliverableReviews;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Las entregas de Sistemas. */
class DeliverableController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => DeliverableReviews::all()]);
    }

    public function approve(Request $request, string $deliverable, DeliverableReviews $reviews): JsonResponse
    {
        $data = $request->validate(
            ['delivered_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.today()->toDateString()]],
            ['delivered_on.before_or_equal' => 'La fecha real no puede ser futura.'],
        );

        $saved = $reviews->approve($request->user(), $deliverable, $data['delivered_on']);

        return response()->json(['data' => $saved->toApi()]);
    }

    public function reject(Request $request, string $deliverable, DeliverableReviews $reviews): JsonResponse
    {
        $data = $request->validate(
            ['note' => ['required', 'string', 'max:500']],
            ['note.required' => 'Escribe qué falta.'],
        );

        $saved = $reviews->reject($request->user(), $deliverable, $data['note']);

        return response()->json(['data' => $saved->toApi()]);
    }
}
