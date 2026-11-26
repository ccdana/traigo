<?php

namespace App\Http\Controllers\Api\V1\Control;

use App\Http\Controllers\Controller;
use App\Services\Control\CashReviews;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Jime valida u observa los cierres de caja. */
class CashClosingController extends Controller
{
    public function show(string $closing): JsonResponse
    {
        return $this->respond($closing);
    }

    public function approve(Request $request, string $closing, CashReviews $reviews): JsonResponse
    {
        $reviews->approve($request->user(), $closing);

        return $this->respond($closing);
    }

    public function observe(Request $request, string $closing, CashReviews $reviews): JsonResponse
    {
        $data = $request->validate(
            ['note' => ['required', 'string', 'max:500']],
            ['note.required' => 'Escribe qué hay que corregir.'],
        );

        $reviews->observe($request->user(), $closing, $data['note']);

        return $this->respond($closing);
    }

    private function respond(string $closing): JsonResponse
    {
        return response()->json(['data' => CashReviews::view($closing)]);
    }
}
