<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- El dueño del indicador determina su responsable en todas las semanas.
            CREATE OR REPLACE VIEW v_kpi_member_week AS
            SELECT r.week_id, i.user_id,
                   count(*)::int AS indicators_total,
                   count(r.actual)::int AS indicators_loaded,
                   round(sum(r.weight_pct * least(coalesce(r.actual, 0) / r.target, 1)), 6) AS partial_score_pct,
                   CASE WHEN count(r.actual) = count(*)
                        THEN round(sum(r.weight_pct * least(r.actual / r.target, 1)), 6)
                   END AS score_pct
            FROM kpi_weekly_results r
            JOIN kpi_indicators i ON i.id_kpi_indicator = r.indicator_id
            GROUP BY r.week_id, i.user_id;

            ALTER TABLE kpi_weekly_results
                DROP CONSTRAINT kpi_weekly_results_indicator_id_user_id_foreign,
                DROP CONSTRAINT kpi_results_not_own_chk,
                DROP COLUMN user_id,
                ADD FOREIGN KEY (indicator_id) REFERENCES kpi_indicators (id_kpi_indicator);
            ALTER TABLE kpi_indicators DROP CONSTRAINT kpi_indicators_id_kpi_indicator_user_id_unique;
            CREATE INDEX kpi_weekly_results_indicator_week ON kpi_weekly_results (indicator_id, week_id);

            CREATE VIEW v_kpi_results AS
            SELECT r.*, i.user_id
            FROM kpi_weekly_results r JOIN kpi_indicators i ON i.id_kpi_indicator = r.indicator_id;

            CREATE OR REPLACE FUNCTION prevent_kpi_self_review() RETURNS trigger LANGUAGE plpgsql AS $body$
            BEGIN
                IF NEW.loaded_by = (SELECT user_id FROM kpi_indicators WHERE id_kpi_indicator = NEW.indicator_id) THEN
                    RAISE EXCEPTION 'Nadie revisa su propio KPI.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $body$;

            CREATE TRIGGER kpi_results_no_self_review
                BEFORE INSERT OR UPDATE OF indicator_id, loaded_by ON kpi_weekly_results
                FOR EACH ROW EXECUTE FUNCTION prevent_kpi_self_review();

            -- Reasignar un indicador crea otra asignación y mantiene el responsable histórico.
            CREATE OR REPLACE FUNCTION preserve_kpi_assignment() RETURNS trigger LANGUAGE plpgsql AS $body$
            BEGIN
                IF NEW.user_id IS DISTINCT FROM OLD.user_id THEN
                    RAISE EXCEPTION 'Para reasignar el indicador, cree otro para la nueva persona.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $body$;

            CREATE TRIGGER kpi_indicators_preserve_assignment BEFORE UPDATE OF user_id ON kpi_indicators
                FOR EACH ROW EXECUTE FUNCTION preserve_kpi_assignment();

            -- Una comisión apunta a la línea, o al pedido cuando el ajuste carece de línea.
            ALTER TABLE commissions
                DROP CONSTRAINT commissions_order_id_seller_id_foreign,
                DROP CONSTRAINT commissions_order_item_id_order_id_foreign,
                ALTER COLUMN order_id DROP NOT NULL,
                DROP COLUMN seller_id;
            ALTER TABLE commissions RENAME COLUMN order_id TO order_adjustment_id;

            ALTER TABLE commissions DISABLE TRIGGER commissions_audit;
            UPDATE commissions SET order_adjustment_id = NULL WHERE order_item_id IS NOT NULL;
            ALTER TABLE commissions ENABLE TRIGGER commissions_audit;

            ALTER TABLE commissions
                ADD FOREIGN KEY (order_item_id) REFERENCES order_items (id_order_item),
                ADD FOREIGN KEY (order_adjustment_id) REFERENCES orders (id_order),
                ADD CONSTRAINT commission_exactly_one_source_chk
                    CHECK (num_nonnulls(order_item_id, order_adjustment_id) = 1);
            ALTER TABLE orders DROP CONSTRAINT orders_id_order_seller_id_unique;
            ALTER TABLE order_items DROP CONSTRAINT order_items_id_order_item_order_id_unique;
            CREATE INDEX commissions_order_item_relationship ON commissions (order_item_id);

            CREATE VIEW v_commission_sources AS
            SELECT c.*, o.id_order AS order_id, o.seller_id
            FROM commissions c
            JOIN order_items i ON i.id_order_item = c.order_item_id
            JOIN orders o ON o.id_order = i.order_id
            UNION ALL
            SELECT c.*, o.id_order AS order_id, o.seller_id
            FROM commissions c
            JOIN orders o ON o.id_order = c.order_adjustment_id;

            CREATE OR REPLACE FUNCTION enforce_commission_seller() RETURNS trigger LANGUAGE plpgsql AS $body$
            DECLARE
                source_order uuid;
                seller uuid;
            BEGIN
                IF TG_TABLE_NAME = 'orders' THEN
                    IF NEW.seller_id IS DISTINCT FROM OLD.seller_id AND EXISTS (
                        SELECT 1 FROM v_commission_sources WHERE order_id = OLD.id_order
                    ) THEN
                        RAISE EXCEPTION 'El vendedor de una venta con comisiones no se puede cambiar.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF TG_TABLE_NAME = 'order_items' THEN
                    IF NEW.order_id IS DISTINCT FROM OLD.order_id AND EXISTS (
                        SELECT 1 FROM commissions WHERE order_item_id = OLD.id_order_item
                    ) THEN
                        RAISE EXCEPTION 'Una línea con comisiones debe conservar su pedido.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF NEW.order_item_id IS NOT NULL THEN
                    SELECT order_id INTO source_order FROM order_items WHERE id_order_item = NEW.order_item_id FOR SHARE;
                ELSE
                    source_order := NEW.order_adjustment_id;
                END IF;
                SELECT seller_id INTO seller FROM orders WHERE id_order = source_order FOR SHARE;
                IF seller IS NULL THEN
                    RAISE EXCEPTION 'La fuente de una comisión debe tener vendedor.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $body$;

            CREATE TRIGGER commissions_source_seller BEFORE INSERT OR UPDATE OF order_item_id, order_adjustment_id
                ON commissions FOR EACH ROW EXECUTE FUNCTION enforce_commission_seller();
            CREATE TRIGGER orders_preserve_commission_seller BEFORE UPDATE OF seller_id
                ON orders FOR EACH ROW EXECUTE FUNCTION enforce_commission_seller();
            CREATE TRIGGER order_items_preserve_commission_order BEFORE UPDATE OF order_id
                ON order_items FOR EACH ROW EXECUTE FUNCTION enforce_commission_seller();
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Restaurar el respaldo previo para revertir la normalización comercial.');
    }
};
