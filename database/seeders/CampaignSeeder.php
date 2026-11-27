<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CampaignSeeder extends Seeder
{
    /**
     * La campaña del 1/12/2026 al 14/1/2027: escalones, semanas, indicadores de
     * cada integrante, el KPI de cada semana (sin valores reales) y las 8
     * entregas de Sistemas. Va después de TeamSeeder.
     */
    public function run(): void
    {
        DB::transaction(function () {
            $campaign = require __DIR__.'/data/campaign.php';
            $users = DB::table('users')->where('role', '<>', 'customer')->pluck('id_user', 'name');

            $policyId = DB::table('bonus_policies')->where('code', 'campaign_2026_12')->value('id_policy');
            DB::table('bonus_tiers')->insert(array_map(fn (array $tier) => $tier + ['policy_id' => $policyId], $campaign['tiers']));
            DB::table('bonus_weeks')->insert(array_map(fn (array $week) => $week + ['policy_id' => $policyId], $campaign['weeks']));

            DB::statement(<<<'SQL'
                INSERT INTO bonus_week_members (week_id, user_id, share_pct, min_kpi_pct, required_deliverables)
                SELECT w.id_bonus_week, p.user_id, p.bonus_share_pct, p.min_kpi_pct,
                       CASE WHEN w.requires_system_deliverables AND u.role = 'systems' THEN 4 ELSE 0 END
                FROM bonus_weeks w CROSS JOIN team_profiles p
                JOIN users u ON u.id_user = p.user_id
                WHERE p.bonus_share_pct > 0
                SQL);

            foreach (require __DIR__.'/data/kpi_indicators.php' as $indicator) {
                $member = $indicator['member'];
                unset($indicator['member']);
                DB::table('kpi_indicators')->insert(['user_id' => $users[$member]] + $indicator);
            }

            // Metas de cada semana, como en el Excel: las de cantidad se reducen
            // en las semanas parciales (redondeando hacia arriba) y las de
            // porcentaje no cambian. numeric de PostgreSQL: sin errores de coma.
            DB::statement(<<<'SQL'
                INSERT INTO kpi_weekly_results (week_id, indicator_id, target, weight_pct)
                SELECT w.id_bonus_week,
                       i.id_kpi_indicator,
                       CASE WHEN i.unit = 'count' THEN ceil(i.base_target * w.count_target_factor)
                            ELSE i.base_target END,
                       i.weight_pct
                FROM bonus_weeks w
                CROSS JOIN kpi_indicators i
                WHERE i.is_active
                SQL);

            DB::statement('INSERT INTO weekly_closings (week_id) SELECT id_bonus_week FROM bonus_weeks');

            $firstWeek = DB::table('bonus_weeks')->where('requires_system_deliverables', true)->value('id_bonus_week');
            foreach (require __DIR__.'/data/system_deliverables.php' as $order => $deliverable) {
                $member = $deliverable['member'];
                unset($deliverable['member']);
                DB::table('system_deliverables')->insert(
                    ['week_id' => $firstWeek, 'user_id' => $users[$member], 'sort_order' => $order] + $deliverable,
                );
            }
        });
    }
}
