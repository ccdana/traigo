<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE order_item_components (
                order_item_id bigint NOT NULL REFERENCES order_items (id_order_item) ON DELETE CASCADE,
                product_id uuid NOT NULL REFERENCES products (id_product),
                quantity_per_combo integer NOT NULL CHECK (quantity_per_combo > 0),
                item_code text NOT NULL,
                name text NOT NULL,
                PRIMARY KEY (order_item_id, product_id)
            );
            CREATE INDEX order_item_components_product ON order_item_components (product_id);

            -- Completar una composición histórica requiere evidencia. Los pedidos
            -- previos quedan visibles y se señalan al intentar mover su stock.
            CREATE OR REPLACE FUNCTION snapshot_combo_items() RETURNS trigger LANGUAGE plpgsql AS $body$
            BEGIN
                IF NEW.combo_id IS NULL THEN
                    RETURN NEW;
                END IF;

                INSERT INTO order_item_components (order_item_id, product_id, quantity_per_combo, item_code, name)
                SELECT NEW.id_order_item, p.id_product, c.quantity, p.item_code, p.name
                FROM combo_items c JOIN products p ON p.id_product = c.product_id
                WHERE c.combo_id = NEW.combo_id;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'El combo vendido debe tener componentes.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $body$;

            CREATE TRIGGER order_items_snapshot_combo AFTER INSERT ON order_items
                FOR EACH ROW EXECUTE FUNCTION snapshot_combo_items();
            CREATE TRIGGER order_item_components_append_only BEFORE UPDATE OR DELETE ON order_item_components
                FOR EACH ROW EXECUTE FUNCTION forbid_history_changes();

            CREATE OR REPLACE FUNCTION preserve_sold_combo() RETURNS trigger LANGUAGE plpgsql AS $body$
            BEGIN
                IF (OLD.combo_id IS NOT NULL OR NEW.combo_id IS NOT NULL)
                   AND (NEW.combo_id, NEW.quantity) IS DISTINCT FROM (OLD.combo_id, OLD.quantity) THEN
                    RAISE EXCEPTION 'Conserve la composición y cantidad del combo vendido.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $body$;

            CREATE TRIGGER order_items_preserve_combo BEFORE UPDATE OF combo_id, quantity ON order_items
                FOR EACH ROW EXECUTE FUNCTION preserve_sold_combo();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER order_items_preserve_combo ON order_items');
        DB::statement('DROP TRIGGER order_items_snapshot_combo ON order_items');
        Schema::drop('order_item_components');
        DB::statement('DROP FUNCTION preserve_sold_combo(), snapshot_combo_items()');
    }
};
