<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentMethod;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Cash\CashDay;
use App\Services\Cash\SendClosing;
use App\Support\ClientKey;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** La caja del día, que cierra almacén. */
class CashController extends Controller
{
    public function today(): JsonResponse
    {
        return $this->respond(today()->toDateString(), withPendingDays: true);
    }

    public function day(string $date): JsonResponse
    {
        [$year, $month, $day] = array_map(intval(...), explode('-', $date));

        if (! checkdate($month, $day, $year)) {
            throw ApiException::notFound('Ese día no tiene caja.');
        }

        CashDay::requireCashDay($date);

        return $this->respond($date);
    }

    public function store(Request $request, SendClosing $closings): JsonResponse
    {
        $data = $request->validate([
            'business_date' => ['required', 'date_format:Y-m-d'],
            'lines' => ['required', 'array', 'max:10'],
            'lines.*.method' => ['required', 'distinct', Rule::enum(PaymentMethod::class)],
            // Sin negativos.
            'lines.*.counted' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'lines.required' => 'Falta lo recibido.',
            'lines.*.counted.required' => 'Falta lo recibido.',
            'lines.*.counted.regex' => 'Revisa los montos recibidos.',
        ]);

        ClientKey::require($request);

        $counted = [];
        foreach ($data['lines'] as $line) {
            $counted[$line['method']] = Money::toCents((string) $line['counted']);
        }

        $closings->send($request->user(), $data['business_date'], $counted, $data['note'] ?? null);

        return $this->respond($data['business_date'], withPendingDays: true);
    }

    private function respond(string $date, bool $withPendingDays = false): JsonResponse
    {
        return response()->json(['data' => CashDay::show($date, $withPendingDays)]);
    }
}
