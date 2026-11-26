<?php

namespace App\Http\Controllers\Api\V1\Control;

use App\Http\Controllers\Controller;
use App\Services\Control\Commissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** La liquidación mensual de comisiones. */
class CommissionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate(
            ['month' => ['nullable', 'date_format:Y-m']],
            ['month.date_format' => 'El mes se escribe como 2026-12.'],
        );

        return $this->respond($data['month'] ?? today()->format('Y-m'));
    }

    public function send(Request $request, string $month, Commissions $commissions): JsonResponse
    {
        $commissions->send($request->user(), $month);

        return $this->respond($month);
    }

    public function paid(string $month, Commissions $commissions): JsonResponse
    {
        $commissions->markPaid($month);

        return $this->respond($month);
    }

    private function respond(string $month): JsonResponse
    {
        return response()->json(['data' => Commissions::month($month)]);
    }
}
