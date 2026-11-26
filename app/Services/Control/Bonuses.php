<?php

namespace App\Services\Control;

use App\Exceptions\ApiException;
use App\Models\BonusWeek;
use App\Support\Dates;
use App\Support\Money;
use App\Support\Numbers;
use Illuminate\Support\Facades\DB;

/**
 * El bono de la semana y sus pagos. La parte de quien no cumple no se reparte;
 * se paga solo con la autorización de Dirección.
 */
class Bonuses
{
    /** @return array<string, mixed> */
    public static function view(BonusWeek $week): array
    {
        $bonus = WeekBonusData::of($week);
        $payable = $bonus->shareOf('passes');

        return [
            'week' => ['number' => $week->number, ...$week->toApi()],
            'current_week' => BonusWeek::current()->number,
            'status' => $bonus->status,
            'collected' => Money::fromDatabase($bonus->collected),
            'achievement_percent' => Numbers::floorPercent($bonus->achievementPct),
            'tier' => $bonus->tier,
            'release_factor' => number_format((float) $bonus->releaseFactor, 2, '.', ''),
            'fund' => Money::format($bonus->fund),
            'payable' => Money::format($payable),
            'unpaid' => Money::format($bonus->fund - $payable),
            'members' => $bonus->members->map(fn (array $row) => [
                'id' => $row['user_id'],
                'name' => $row['name'],
                'fund_share_rate' => Numbers::rate($row['share_pct']),
                'compliance' => $row['result'],
                'share_amount' => Money::format($row['share']),
                'amount' => $row['amount'] === null ? null : Money::format($row['amount']),
                'paid_at' => Dates::toApi($row['paid_at']),
                'closed_reason' => $row['closed_reason'],
            ])->values()->all(),
        ];
    }

    public function markPaid(BonusWeek $week, string $memberId): void
    {
        DB::transaction(function () use ($week, $memberId) {
            $status = DB::table('weekly_closings')->where('week_id', $week->getKey())->lockForUpdate()->value('status');

            if ($status !== 'authorized' && $status !== 'paid') {
                throw ApiException::conflict('not_ready_to_pay', 'Todavía no lo autorizó Dirección.');
            }

            $row = DB::table('weekly_member_results')
                ->where('week_id', $week->getKey())
                ->where('user_id', $memberId)
                ->first();

            if (! $row || $row->result !== 'passes' || $row->paid_at !== null) {
                throw ApiException::conflict('not_ready_to_pay', 'Ese pago no está pendiente.');
            }

            DB::table('weekly_member_results')
                ->where('week_id', $week->getKey())
                ->where('user_id', $memberId)
                ->update(['paid_at' => now()]);

            // Pagados todos los que tenían algo que cobrar, la semana queda pagada.
            $unpaid = DB::table('weekly_member_results')
                ->where('week_id', $week->getKey())
                ->where('result', 'passes')
                ->where('amount_bs', '>', 0)
                ->whereNull('paid_at')
                ->exists();

            if (! $unpaid && $status === 'authorized') {
                DB::table('weekly_closings')->where('week_id', $week->getKey())
                    ->update(['status' => 'paid', 'paid_at' => now(), 'updated_at' => now()]);
            }
        });
    }
}
