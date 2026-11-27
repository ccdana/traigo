<?php

namespace App\Services\Team;

use App\Enums\UserRole;
use App\Http\Resources\SaleOrder;
use App\Models\BonusWeek;
use App\Models\Order;
use App\Models\User;
use App\Services\Cash\CashDay;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/** El Inicio de un integrante: solo lo suyo. */
final class TeamHome
{
    /** @return array<string, mixed> */
    public static function of(User $member): array
    {
        $week = BonusWeek::current();
        $kpi = MemberKpi::of($member, $week);
        $sells = $member->role->canSell();

        return [
            'week' => $week->toApi(),
            'collected' => $week->collected(),
            'fund_share_rate' => $kpi->shareRate(),
            'kpi' => [
                'score_percent' => $kpi->partialScorePercent(),
                'minimum_percent' => $kpi->minimumPercent(),
                'compliance' => $kpi->result->result,
                'deliverables' => $kpi->deliverableProgress(),
                'closed_reason' => $kpi->result->closed_reason,
                'indicators' => $kpi->indicators()->map(fn (array $indicator) => collect($indicator)->only(
                    ['id', 'name', 'target', 'actual', 'weight', 'evidence_status'],
                ))->values(),
            ],
            'month_commission' => $sells ? self::monthCommission($member) : null,
            'today_sales' => $sells ? self::todaySales($member) : [],
            'cash_today' => $member->role === UserRole::Warehouse ? CashDay::homeSummary() : null,
        ];
    }

    /**
     * Lo cobrado del mes con sus ajustes. Sin monto mientras alguna venta
     * cobrada no tenga % (decidido en la fase 3).
     *
     * @return array{amount: ?string, pay_note: ?string}
     */
    private static function monthCommission(User $seller): array
    {
        $earned = DB::table('v_commission_sources')
            ->where('seller_id', $seller->getKey())
            ->where('status', 'earned')
            ->where('earned_at', '>=', now()->startOfMonth())
            ->where('earned_at', '<', now()->startOfMonth()->addMonth())
            ->pluck('amount_bs');

        $note = DB::table('settings')->where('key', 'commission_pay_note')->value('value');

        return [
            'amount' => $earned->containsStrict(null)
                ? null
                : Money::format($earned->sum(fn ($amount) => Money::toCents($amount))),
            'pay_note' => $note === null ? null : json_decode($note),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function todaySales(User $seller): array
    {
        return Order::query()
            ->with(['items', 'currentPayment'])
            ->where('seller_id', $seller->getKey())
            ->where('created_at', '>=', today())
            ->where('created_at', '<', today()->addDay())
            ->whereNotIn('status', ['cancelled', 'expired'])
            ->orderByDesc('created_at')
            ->orderByDesc('id_order')
            ->get()
            ->map(fn (Order $order) => collect(SaleOrder::summary($order))->only(
                ['id', 'created_at', 'items_label', 'customer_name', 'total', 'payment_method'],
            )->all())
            ->all();
    }
}
