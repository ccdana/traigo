<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE legacy_internal_identifiers (
                relation_name text NOT NULL,
                legacy_uuid uuid NOT NULL,
                internal_id bigint NOT NULL CHECK (internal_id > 0),
                PRIMARY KEY (relation_name, legacy_uuid),
                UNIQUE (relation_name, internal_id)
            );

            -- Esta vista y estas FK usan las claves que cambian de tipo.
            DROP VIEW attendance_marks;

            CREATE TEMP TABLE traigo_internal_fks ON COMMIT DROP AS
            SELECT c.conrelid::regclass AS relation, c.conname, pg_get_constraintdef(c.oid) AS definition
            FROM pg_constraint c
            WHERE c.contype = 'f' AND c.confrelid IN (
                'order_items'::regclass, 'commission_settlements'::regclass,
                'attendance_sites'::regclass, 'attendance_policies'::regclass,
                'attendance_geofences'::regclass, 'attendance_assignments'::regclass
            );

            CREATE FUNCTION pg_temp.traigo_internal_id(relation_key text, old_id uuid)
            RETURNS bigint LANGUAGE plpgsql STABLE STRICT AS $body$
            DECLARE
                result bigint;
            BEGIN
                SELECT internal_id INTO STRICT result FROM legacy_internal_identifiers
                WHERE relation_name = relation_key AND legacy_uuid = old_id;
                RETURN result;
            END;
            $body$;

            DO $body$
            DECLARE
                key_row record;
                fk record;
                largest bigint;
            BEGIN
                LOCK TABLE order_items, commissions, commission_settlements, cash_closing_lines,
                    product_attributes, combo_images, attendance_sites, attendance_policies,
                    attendance_geofences, attendance_assignments, attendance_attempts IN ACCESS EXCLUSIVE MODE;

                FOR fk IN SELECT * FROM traigo_internal_fks LOOP
                    EXECUTE format('ALTER TABLE %s DROP CONSTRAINT %I', fk.relation, fk.conname);
                END LOOP;

                FOR key_row IN SELECT * FROM (VALUES
                    ('order_items', 'id_order_item'),
                    ('commissions', 'id_commission'),
                    ('commission_settlements', 'id_commission_settlement'),
                    ('cash_closing_lines', 'id_cash_closing_line'),
                    ('product_attributes', 'id_product_attribute'),
                    ('combo_images', 'id_combo_image'),
                    ('attendance_sites', 'id_site'),
                    ('attendance_policies', 'id_policy'),
                    ('attendance_geofences', 'id_geofence'),
                    ('attendance_assignments', 'id_assignment'),
                    ('attendance_attempts', 'id_attempt')
                ) AS keys(relation_name, key_name) LOOP
                    EXECUTE format('INSERT INTO legacy_internal_identifiers
                        SELECT %L, %I, row_number() OVER (ORDER BY %I) FROM %I',
                        key_row.relation_name, key_row.key_name, key_row.key_name, key_row.relation_name);
                    EXECUTE format('ALTER TABLE %I ALTER COLUMN %I DROP DEFAULT',
                        key_row.relation_name, key_row.key_name);
                    EXECUTE format('ALTER TABLE %I ALTER COLUMN %I TYPE bigint
                        USING pg_temp.traigo_internal_id(%L, %I)',
                        key_row.relation_name, key_row.key_name, key_row.relation_name, key_row.key_name);
                    EXECUTE format('ALTER TABLE %I ALTER COLUMN %I ADD GENERATED ALWAYS AS IDENTITY',
                        key_row.relation_name, key_row.key_name);
                    SELECT max(internal_id) INTO largest FROM legacy_internal_identifiers
                        WHERE relation_name = key_row.relation_name;
                    PERFORM setval(pg_get_serial_sequence(key_row.relation_name, key_row.key_name),
                        coalesce(largest, 1), largest IS NOT NULL);
                END LOOP;

                ALTER TABLE commissions
                    ALTER COLUMN order_item_id TYPE bigint USING pg_temp.traigo_internal_id('order_items', order_item_id),
                    ALTER COLUMN settlement_id TYPE bigint USING pg_temp.traigo_internal_id('commission_settlements', settlement_id);
                ALTER TABLE attendance_geofences
                    ALTER COLUMN site_id TYPE bigint USING pg_temp.traigo_internal_id('attendance_sites', site_id),
                    ALTER COLUMN policy_id TYPE bigint USING pg_temp.traigo_internal_id('attendance_policies', policy_id);
                ALTER TABLE attendance_assignments
                    ALTER COLUMN site_id TYPE bigint USING pg_temp.traigo_internal_id('attendance_sites', site_id);
                ALTER TABLE attendance_attempts
                    ALTER COLUMN assignment_id TYPE bigint USING pg_temp.traigo_internal_id('attendance_assignments', assignment_id),
                    ALTER COLUMN geofence_id TYPE bigint USING pg_temp.traigo_internal_id('attendance_geofences', geofence_id);

                FOR fk IN SELECT * FROM traigo_internal_fks LOOP
                    EXECUTE format('ALTER TABLE %s ADD CONSTRAINT %I %s', fk.relation, fk.conname, fk.definition);
                END LOOP;
            END;
            $body$;

            DROP FUNCTION pg_temp.traigo_internal_id(text, uuid);

            CREATE VIEW attendance_marks AS
            SELECT id_attempt AS id_mark, assignment_id, geofence_id, kind, received_at AS marked_at
            FROM attendance_attempts WHERE outcome = 'accepted';
            SQL);
    }

    public function down(): void
    {
        // Una restauración previa conserva también las claves y eventos históricos.
        throw new RuntimeException('Restaurar el respaldo previo para revertir el cambio de identificadores.');
    }
};
