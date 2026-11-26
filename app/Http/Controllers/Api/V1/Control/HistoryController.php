<?php

namespace App\Http\Controllers\Api\V1\Control;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\BonusWeek;
use App\Services\Cash\CashDay;
use App\Services\Control\AuditHistory;
use App\Services\Control\SystemStatus;
use App\Services\Reports\CashReport;
use App\Services\Reports\CommissionReport;
use App\Services\Reports\WeekReport;
use App\Support\Responses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** El historial, los reportes en Excel y el estado de los respaldos. */
class HistoryController extends Controller
{
    public function audit(Request $request, AuditHistory $history): JsonResponse
    {
        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:100000']]);

        return Responses::paginated(AuditHistory::page((int) ($data['page'] ?? 1)), $history->entry(...));
    }

    public function report(Request $request, string $type): BinaryFileResponse
    {
        $period = (string) $request->query('period');

        return match ($type) {
            'week' => $this->week($period),
            'cash' => $this->cash($period),
            'commissions' => $this->commissions($period),
        };
    }

    public function status(): JsonResponse
    {
        return response()->json(['data' => SystemStatus::toApi()]);
    }

    private function week(string $period): BinaryFileResponse
    {
        if (! ctype_digit($period) || strlen($period) > 3) {
            throw ApiException::invalid('Falta el número de semana.');
        }

        $week = BonusWeek::started((int) $period);

        return WeekReport::of($week)->download("traigo-semana-{$week->number}.xlsx");
    }

    private function cash(string $period): BinaryFileResponse
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $period, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw ApiException::invalid('Falta la fecha (AAAA-MM-DD).');
        }

        CashDay::requireCashDay($period);

        return CashReport::of($period)->download("traigo-caja-{$period}.xlsx");
    }

    private function commissions(string $period): BinaryFileResponse
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw ApiException::invalid('Falta el mes (AAAA-MM).');
        }

        return CommissionReport::of($period)->download("traigo-comisiones-{$period}.xlsx");
    }
}
