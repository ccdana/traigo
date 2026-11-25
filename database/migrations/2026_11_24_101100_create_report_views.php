<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Vistas que repiten las fórmulas del Excel de Jime, para que el backend no
     * las reescriba: puntaje KPI, cumple/no cumple, cobrado de la semana, escalón
     * del premio y lo que le toca a cada integrante (vista previa).
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Cada indicador avanza como máximo 100 %: un exceso no compensa otro.
            -- score_pct es nulo mientras falte cargar algún indicador.
            CREATE VIEW v_kpi_member_week AS
            SELECT week_id,
                   user_id,
                   count(*)::int AS indicators_total,
                   count(actual)::int AS indicators_loaded,
                   round(sum(weight_pct * least(coalesce(actual, 0) / target, 1)), 6) AS partial_score_pct,
                   CASE WHEN count(actual) = count(*)
                        THEN round(sum(weight_pct * least(actual / target, 1)), 6)
                   END AS score_pct
            FROM kpi_weekly_results
            GROUP BY week_id, user_id;

            -- Las 4 entregas de cada ingeniero: todas a tiempo o no hay primer bono.
            CREATE VIEW v_deliverables_member AS
            SELECT week_id,
                   user_id,
                   count(*)::int AS total,
                   count(*) FILTER (WHERE result = 'on_time')::int AS on_time,
                   count(*) FILTER (WHERE result = 'pending')::int AS pending,
                   (CASE
                        WHEN count(*) FILTER (WHERE result = 'pending') > 0 THEN 'pending'
                        WHEN count(*) FILTER (WHERE result = 'on_time') = count(*) THEN 'passes'
                        ELSE 'fails'
                    END)::compliance_result AS status
            FROM system_deliverables
            GROUP BY week_id, user_id;

            -- Solo según lo cargado; la usa la base para saber quién está pendiente.
            CREATE VIEW v_member_week_compliance_raw AS
            SELECT w.id_bonus_week AS week_id,
                   p.user_id,
                   p.bonus_share_pct AS share_pct,
                   p.min_kpi_pct,
                   coalesce(k.indicators_total, 0) AS indicators_total,
                   coalesce(k.indicators_loaded, 0) AS indicators_loaded,
                   coalesce(k.partial_score_pct, 0) AS partial_score_pct,
                   k.score_pct,
                   d.total AS deliverables_total,
                   d.on_time AS deliverables_on_time,
                   (CASE
                        WHEN k.score_pct IS NULL THEN 'pending'
                        WHEN w.requires_system_deliverables AND d.status = 'pending' THEN 'pending'
                        WHEN k.score_pct >= p.min_kpi_pct
                             AND (NOT w.requires_system_deliverables OR coalesce(d.status, 'passes') = 'passes')
                             THEN 'passes'
                        ELSE 'fails'
                    END)::compliance_result AS result
            FROM bonus_weeks w
            CROSS JOIN team_profiles p
            LEFT JOIN v_kpi_member_week k ON k.week_id = w.id_bonus_week AND k.user_id = p.user_id
            LEFT JOIN v_deliverables_member d ON d.week_id = w.id_bonus_week AND d.user_id = p.user_id
            WHERE p.bonus_share_pct > 0;

            -- El resultado que se muestra: si Jime cerró a mano a alguien que seguía
            -- pendiente, esa persona no cumple (closed_reason dice por qué).
            CREATE VIEW v_member_week_compliance AS
            SELECT c.week_id,
                   c.user_id,
                   c.share_pct,
                   c.min_kpi_pct,
                   c.indicators_total,
                   c.indicators_loaded,
                   c.partial_score_pct,
                   c.score_pct,
                   c.deliverables_total,
                   c.deliverables_on_time,
                   (CASE WHEN r.closed_by IS NOT NULL THEN 'fails' ELSE c.result END)::compliance_result AS result,
                   r.close_reason AS closed_reason
            FROM v_member_week_compliance_raw c
            LEFT JOIN weekly_member_results r
                   ON r.week_id = c.week_id AND r.user_id = c.user_id AND r.closed_by IS NOT NULL;

            -- Ventas cobradas (confirmadas y no devueltas) según el día en Bolivia en
            -- que llegó el dinero.
            CREATE VIEW v_week_collected AS
            SELECT w.id_bonus_week AS week_id,
                   coalesce(sum(p.amount_bs), 0)::numeric(12, 2) AS collected_bs
            FROM bonus_weeks w
            LEFT JOIN payments p
                   ON p.status = 'confirmed'
                  AND (p.received_at AT TIME ZONE 'America/La_Paz')::date BETWEEN w.starts_on AND w.ends_on
            GROUP BY w.id_bonus_week;

            CREATE VIEW v_week_bonus AS
            SELECT w.id_bonus_week AS week_id,
                   c.collected_bs,
                   w.sales_target_bs,
                   trunc(c.collected_bs * 100 / w.sales_target_bs, 2) AS achievement_pct,
                   coalesce(t.tier, 'none'::bonus_tier) AS tier,
                   w.base_prize_bs,
                   coalesce(t.release_factor, 0) AS release_factor,
                   round(w.base_prize_bs * coalesce(t.release_factor, 0), 2) AS fund_bs
            FROM bonus_weeks w
            JOIN v_week_collected c ON c.week_id = w.id_bonus_week
            LEFT JOIN LATERAL (
                SELECT tier, release_factor
                FROM bonus_tiers
                WHERE c.collected_bs * 100 >= threshold_pct * w.sales_target_bs
                ORDER BY threshold_pct DESC
                LIMIT 1
            ) t ON true;

            -- amount_bs nulo = pendiente. La parte de quien no cumple no se reparte.
            CREATE VIEW v_week_member_bonus AS
            SELECT m.week_id,
                   m.user_id,
                   m.share_pct,
                   m.min_kpi_pct,
                   m.score_pct,
                   m.result,
                   m.closed_reason,
                   CASE m.result
                        WHEN 'passes' THEN round(b.fund_bs * m.share_pct / 100, 2)
                        WHEN 'fails' THEN 0
                   END AS amount_bs,
                   round(b.fund_bs * m.share_pct / 100, 2) AS share_amount_bs
            FROM v_member_week_compliance m
            JOIN v_week_bonus b ON b.week_id = m.week_id;
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['v_week_member_bonus', 'v_week_bonus', 'v_week_collected', 'v_member_week_compliance', 'v_member_week_compliance_raw', 'v_deliverables_member', 'v_kpi_member_week'] as $view) {
            DB::statement("DROP VIEW IF EXISTS {$view}");
        }
    }
};
