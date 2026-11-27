<?php

namespace Tests\Feature\Api;

use App\Models\BonusWeek;
use Database\Seeders\CampaignSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * La campaña real sobre el catálogo y el equipo. El reloj queda en el jueves
 * 3 de diciembre de 2026 (primera semana), así nada depende de cuándo se
 * corran las pruebas.
 */
abstract class CampaignTestCase extends SalesTestCase
{
    private string $disk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CampaignSeeder::class, SettingsSeeder::class]);
        $this->travelTo('2026-12-03 12:00:00');

        $this->disk = storage_path('framework/testing/archivos-'.Str::random(8));
        config(['filesystems.disks.local.root' => $this->disk]);
        Storage::forgetDisk('local');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->disk);

        parent::tearDown();
    }

    protected function week(int $number = 1): BonusWeek
    {
        return BonusWeek::query()->where('number', $number)->firstOrFail();
    }

    /** El resultado semanal de un indicador, por su nombre. */
    protected function resultId(string $code, string $indicator, int $week = 1): string
    {
        return DB::table('kpi_weekly_results as r')
            ->join('kpi_indicators as i', 'i.id_kpi_indicator', '=', 'r.indicator_id')
            ->where('r.week_id', $this->week($week)->getKey())
            ->where('i.user_id', $this->member($code)->getKey())
            ->where('i.name', $indicator)
            ->value('r.id_kpi_weekly_result');
    }

    /** Jime carga los valores reales (por defecto, la meta). */
    protected function loadActuals(string $code, ?float $factor = 1.0, int $week = 1): void
    {
        DB::update(
            'update kpi_weekly_results set actual = round(target * ?::numeric, 2), loaded_by = ?, loaded_at = now()
             where week_id = ? and indicator_id in (select id_kpi_indicator from kpi_indicators where user_id = ?)',
            [$factor, $this->member('JIM-01')->getKey(), $this->week($week)->getKey(), $this->member($code)->getKey()],
        );
    }

    /** Confirma el cobro de un pedido, como hará Jime. */
    protected function confirmPayment(string $orderId): void
    {
        DB::table('payments')->where('order_id', $orderId)->update([
            'status' => 'confirmed',
            'proof_url' => 'comprobantes/prueba.jpg',
            'submitted_at' => now(),
            'reviewed_by' => $this->member('JIM-01')->getKey(),
            'reviewed_at' => now(),
            'received_at' => now(),
        ]);
        DB::table('orders')->where('id_order', $orderId)->update(['status' => 'paid', 'paid_at' => now()]);
        \App\Services\Control\Commissions::forOrder($orderId)->update(['status' => 'earned', 'earned_at' => now()]);
    }

    /** Jime cierra a mano a quien sigue pendiente. */
    protected function closeByHand(string $code, string $reason, int $week = 1): void
    {
        DB::table('weekly_member_results')->insert([
            'week_id' => $this->week($week)->getKey(),
            'user_id' => $this->member($code)->getKey(),
            'share_pct' => 20,
            'min_kpi_pct' => 90,
            'result' => 'fails',
            'closed_by' => $this->member('JIM-01')->getKey(),
            'closed_at' => now(),
            'close_reason' => $reason,
        ]);
    }

    /** La semana autorizada por Dirección, con las reglas de la base. */
    protected function authorizeWeek(int $week = 1): void
    {
        $weekId = $this->week($week)->getKey();
        foreach (DB::table('team_profiles')->get() as $profile) {
            DB::table('weekly_member_results')->insert([
                'week_id' => $weekId,
                'user_id' => $profile->user_id,
                'share_pct' => $profile->bonus_share_pct,
                'min_kpi_pct' => $profile->min_kpi_pct,
                'result' => 'fails',
                'amount_bs' => 0,
            ]);
        }

        DB::table('weekly_closings')->where('week_id', $weekId)->update([
            'status' => 'authorized',
            'payments_reconciled' => true,
            'kpi_evidence_ok' => true,
            'duplicates_cleared' => true,
            'direction_approved' => true,
            'collected_bs' => 0,
            'achievement_pct' => 0,
            'tier' => 'none',
            'base_prize_bs' => 1500,
            'release_factor' => 0,
            'fund_bs' => 0,
            'sent_by' => $this->member('JIM-01')->getKey(),
            'sent_at' => now(),
            'authorized_by' => $this->member('DIR-08')->getKey(),
            'authorized_at' => now(),
        ]);
    }
}
