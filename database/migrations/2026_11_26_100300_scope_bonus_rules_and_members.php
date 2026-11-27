<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE bonus_policies (
                id_policy bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                code text NOT NULL UNIQUE CHECK (btrim(code) <> '')
            );
            INSERT INTO bonus_policies (code) VALUES ('campaign_2026_12');

            ALTER TABLE bonus_weeks ADD COLUMN policy_id bigint REFERENCES bonus_policies (id_policy);
            ALTER TABLE bonus_tiers ADD COLUMN policy_id bigint REFERENCES bonus_policies (id_policy);
            UPDATE bonus_weeks SET policy_id = (SELECT id_policy FROM bonus_policies WHERE code = 'campaign_2026_12');
            UPDATE bonus_tiers SET policy_id = (SELECT id_policy FROM bonus_policies WHERE code = 'campaign_2026_12');
            ALTER TABLE bonus_weeks ALTER COLUMN policy_id SET NOT NULL;
            ALTER TABLE bonus_tiers
                ALTER COLUMN policy_id SET NOT NULL,
                DROP CONSTRAINT bonus_tiers_pkey,
                DROP CONSTRAINT bonus_tiers_threshold_pct_unique,
                ADD PRIMARY KEY (policy_id, tier),
                ADD UNIQUE (policy_id, threshold_pct);
            CREATE INDEX bonus_weeks_policy ON bonus_weeks (policy_id);

            CREATE TABLE bonus_week_members (
                week_id uuid NOT NULL REFERENCES bonus_weeks (id_bonus_week),
                user_id uuid NOT NULL REFERENCES users (id_user),
                share_pct numeric(5, 2) NOT NULL CHECK (share_pct > 0 AND share_pct <= 100),
                min_kpi_pct numeric(5, 2) NOT NULL CHECK (min_kpi_pct BETWEEN 0 AND 100),
                required_deliverables smallint NOT NULL DEFAULT 0 CHECK (required_deliverables >= 0),
                PRIMARY KEY (week_id, user_id)
            );
            CREATE INDEX bonus_week_members_user ON bonus_week_members (user_id);

            -- Semanas abiertas: usar el equipo actual cuando no hay un cierre guardado.
            INSERT INTO bonus_week_members (week_id, user_id, share_pct, min_kpi_pct, required_deliverables)
            SELECT w.id_bonus_week, p.user_id, p.bonus_share_pct, p.min_kpi_pct,
                   CASE WHEN w.requires_system_deliverables AND u.role = 'systems' THEN 4 ELSE 0 END
            FROM bonus_weeks w CROSS JOIN team_profiles p
            JOIN users u ON u.id_user = p.user_id
            LEFT JOIN weekly_closings c ON c.week_id = w.id_bonus_week
            WHERE p.bonus_share_pct > 0 AND coalesce(c.status, 'open') IN ('open', 'returned');

            -- Los cierres ya enviados conservan los integrantes y condiciones que se enviaron.
            INSERT INTO bonus_week_members (week_id, user_id, share_pct, min_kpi_pct, required_deliverables)
            SELECT r.week_id, r.user_id, r.share_pct, r.min_kpi_pct,
                   CASE WHEN w.requires_system_deliverables AND
                        (u.role = 'systems' OR EXISTS (
                            SELECT 1 FROM system_deliverables d WHERE d.week_id = r.week_id AND d.user_id = r.user_id
                        )) THEN 4 ELSE 0 END
            FROM weekly_member_results r
            JOIN bonus_weeks w ON w.id_bonus_week = r.week_id
            JOIN users u ON u.id_user = r.user_id
            WHERE r.share_pct > 0
            ON CONFLICT (week_id, user_id) DO UPDATE
            SET share_pct = excluded.share_pct, min_kpi_pct = excluded.min_kpi_pct,
                required_deliverables = excluded.required_deliverables;

            ALTER TABLE system_deliverables DROP CONSTRAINT system_deliverables_user_id_title_unique,
                ADD UNIQUE (week_id, user_id, title);

            CREATE OR REPLACE VIEW v_member_week_compliance_raw AS
            SELECT w.id_bonus_week AS week_id,
                   p.user_id,
                   p.share_pct,
                   p.min_kpi_pct,
                   coalesce(k.indicators_total, 0) AS indicators_total,
                   coalesce(k.indicators_loaded, 0) AS indicators_loaded,
                   coalesce(k.partial_score_pct, 0) AS partial_score_pct,
                   k.score_pct,
                   d.total AS deliverables_total,
                   d.on_time AS deliverables_on_time,
                   (CASE
                        WHEN k.score_pct IS NULL THEN 'pending'
                        WHEN p.required_deliverables > coalesce(d.total, 0)
                             OR (p.required_deliverables > 0 AND d.status = 'pending') THEN 'pending'
                        WHEN k.score_pct >= p.min_kpi_pct
                             AND (p.required_deliverables = 0 OR (d.status = 'passes' AND d.on_time >= p.required_deliverables))
                             THEN 'passes'
                        ELSE 'fails'
                    END)::compliance_result AS result
            FROM bonus_weeks w
            JOIN bonus_week_members p ON p.week_id = w.id_bonus_week
            LEFT JOIN v_kpi_member_week k ON k.week_id = w.id_bonus_week AND k.user_id = p.user_id
            LEFT JOIN v_deliverables_member d ON d.week_id = w.id_bonus_week AND d.user_id = p.user_id;

            CREATE OR REPLACE VIEW v_week_bonus AS
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
                WHERE policy_id = w.policy_id AND c.collected_bs * 100 >= threshold_pct * w.sales_target_bs
                ORDER BY threshold_pct DESC
                LIMIT 1
            ) t ON true;

            CREATE OR REPLACE FUNCTION weekly_closing_ready_to_pay() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                expected integer;
                resolved integer;
                shares numeric;
                payable numeric;
            BEGIN
                IF NEW.status NOT IN ('authorized', 'paid') THEN
                    RETURN NEW;
                END IF;

                SELECT count(*) INTO expected FROM bonus_week_members WHERE week_id = NEW.week_id;
                IF EXISTS (
                    SELECT 1 FROM weekly_member_results r
                    LEFT JOIN bonus_week_members m USING (week_id, user_id)
                    WHERE r.week_id = NEW.week_id AND (m.user_id IS NULL OR
                          (r.share_pct, r.min_kpi_pct) IS DISTINCT FROM (m.share_pct, m.min_kpi_pct))
                ) THEN
                    RAISE EXCEPTION 'El cierre debe respetar la asignación de bono de la semana.'
                        USING ERRCODE = 'check_violation';
                END IF;
                SELECT count(*) FILTER (WHERE result <> 'pending'), coalesce(sum(share_pct), 0), coalesce(sum(amount_bs), 0)
                    INTO resolved, shares, payable
                    FROM weekly_member_results WHERE week_id = NEW.week_id;

                IF expected = 0 OR resolved <> expected THEN
                    RAISE EXCEPTION 'No se puede autorizar: faltan resultados o hay integrantes pendientes (% de %).', resolved, expected
                        USING ERRCODE = 'check_violation';
                END IF;
                IF shares <> 100 THEN
                    RAISE EXCEPTION 'No se puede autorizar: el reparto del bono suma % %%, debe sumar 100 %%.', shares
                        USING ERRCODE = 'check_violation';
                END IF;
                IF payable > NEW.fund_bs THEN
                    RAISE EXCEPTION 'No se puede autorizar: se pagaría más que el fondo de la semana.'
                        USING ERRCODE = 'check_violation';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM users WHERE id_user = NEW.authorized_by AND role = 'management') THEN
                    RAISE EXCEPTION 'Solo Dirección puede autorizar el bono.' USING ERRCODE = 'check_violation';
                END IF;
                IF EXISTS (SELECT 1 FROM weekly_member_results WHERE week_id = NEW.week_id AND user_id = NEW.authorized_by) THEN
                    RAISE EXCEPTION 'Nadie puede autorizar su propio bono.' USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END $$;
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Restaurar el respaldo previo para revertir las políticas semanales.');
    }
};
