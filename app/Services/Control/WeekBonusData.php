<?php

namespace App\Services\Control;

use App\Models\BonusWeek;
use App\Services\Team\WeekLock;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * El bono de una semana. Antes de enviar el cierre, la vista previa de las
 * vistas de la base (las fórmulas del Excel); después, la foto guardada.
 */
final class WeekBonusData
{
    /**
     * @param  Collection<int, array{user_id: string, name: string, title: ?string, share_pct: string, result: string, share: int, amount: ?int, paid_at: ?string, closed_reason: ?string}>  $members  en centavos, en el orden de la app
     */
    private function __construct(
        public readonly string $status,
        public readonly string $collected,
        public readonly string $achievementPct,
        public readonly string $tier,
        public readonly string $releaseFactor,
        public readonly int $fund,
        public readonly Collection $members,
    ) {}

    public static function of(BonusWeek $week): self
    {
        $closing = DB::table('weekly_closings')->where('week_id', $week->getKey())->first();
        $status = $closing->status ?? 'open';

        if (in_array($status, WeekLock::FROZEN, true)) {
            return new self(
                $status,
                $closing->collected_bs,
                $closing->achievement_pct,
                $closing->tier,
                $closing->release_factor,
                Money::toCents($closing->fund_bs),
                self::saved($week, $closing->fund_bs),
            );
        }

        $preview = DB::table('v_week_bonus')->where('week_id', $week->getKey())->first();

        return new self(
            $status,
            $preview->collected_bs,
            $preview->achievement_pct,
            $preview->tier,
            $preview->release_factor,
            Money::toCents($preview->fund_bs),
            self::preview($week),
        );
    }

    /** La parte de quienes tienen ese resultado, en centavos. */
    public function shareOf(string $result): int
    {
        return $this->members->where('result', $result)->sum('share');
    }

    private static function saved(BonusWeek $week, string $fund): Collection
    {
        return self::ordered(DB::table('weekly_member_results as r')->where('r.week_id', $week->getKey()))
            ->selectRaw('r.user_id, u.name, u.title, r.share_pct, r.result, round(?::numeric * r.share_pct / 100, 2) as share_amount_bs, r.amount_bs, r.paid_at, r.close_reason as closed_reason', [$fund])
            ->get()
            ->map(fn (object $row) => self::member($row, $row->result === 'pending' ? null : $row->amount_bs));
    }

    private static function preview(BonusWeek $week): Collection
    {
        return self::ordered(DB::table('v_week_member_bonus as r')->where('r.week_id', $week->getKey()))
            ->selectRaw('r.user_id, u.name, u.title, r.share_pct, r.result, r.share_amount_bs, r.amount_bs, null as paid_at, r.closed_reason')
            ->get()
            ->map(fn (object $row) => self::member($row, $row->amount_bs));
    }

    private static function ordered($query)
    {
        return $query
            ->join('users as u', 'u.id_user', '=', 'r.user_id')
            ->leftJoin('team_profiles as p', 'p.user_id', '=', 'r.user_id')
            ->orderBy('p.sort_order')->orderBy('r.user_id');
    }

    private static function member(object $row, ?string $amount): array
    {
        return [
            'user_id' => $row->user_id,
            'name' => $row->name,
            'title' => $row->title,
            'share_pct' => $row->share_pct,
            'result' => $row->result,
            'share' => Money::toCents($row->share_amount_bs),
            'amount' => $amount === null ? null : Money::toCents($amount),
            'paid_at' => $row->paid_at,
            'closed_reason' => $row->closed_reason,
        ];
    }
}
