<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\Store\AdminStatistics;
use App\Services\Warehouse\StockOverview;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DashboardController extends Controller
{
    public function __invoke(Request $request, AdminStatistics $statistics, StockOverview $stock): View
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'until' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'channel' => ['nullable', Rule::in(['all', 'web', 'team'])],
        ]);
        $until = Carbon::parse($data['until'] ?? today()->toDateString())->startOfDay();
        $from = Carbon::parse($data['from'] ?? $until->copy()->subDays(29)->toDateString())->startOfDay();
        if ($from->gt($until) || $from->diffInDays($until) > 365) {
            throw ValidationException::withMessages(['from' => 'Elige un período ordenado de hasta un año.']);
        }
        $channel = $data['channel'] ?? 'all';

        return view('staff.dashboard', [
            ...$statistics->forPeriod($from, $until, $channel),
            'stock' => $stock->summary(), 'from' => $from, 'until' => $until, 'channel' => $channel,
        ]);
    }
}
