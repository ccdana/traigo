<?php

namespace App\Http\Controllers\Api\V1\Control;

use App\Http\Controllers\Controller;
use App\Services\Control\Payments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Conciliación de comprobantes. */
class PaymentController extends Controller
{
    public function show(string $payment): JsonResponse
    {
        return $this->respond($payment);
    }

    public function confirm(Request $request, string $payment, Payments $payments): JsonResponse
    {
        $data = $request->validate([
            'amount_matches' => ['required', 'boolean'],
            'destination_ok' => ['required', 'boolean'],
            'received_at' => ['nullable', 'date'],
        ]);

        $payments->confirm(
            $request->user(),
            $payment,
            $request->boolean('amount_matches'),
            $request->boolean('destination_ok'),
            // En la hora de Bolivia, como se guarda en la base.
            isset($data['received_at'])
                ? Carbon::parse($data['received_at'])->timezone(config('app.timezone'))
                : null,
        );

        return $this->respond($payment);
    }

    public function reject(Request $request, string $payment, Payments $payments): JsonResponse
    {
        $data = $request->validate(
            ['reason' => ['required', 'string', 'max:500']],
            ['reason.required' => 'Escribe el motivo.'],
        );

        $payments->reject($request->user(), $payment, $data['reason']);

        return $this->respond($payment);
    }

    private function respond(string $payment): JsonResponse
    {
        return response()->json(['data' => Payments::view($payment)]);
    }
}
