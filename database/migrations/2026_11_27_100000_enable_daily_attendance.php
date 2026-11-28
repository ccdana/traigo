<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE attendance_attempts ADD COLUMN business_date date
                GENERATED ALWAYS AS ((received_at AT TIME ZONE 'America/La_Paz')::date) STORED;
            ALTER TABLE attendance_attempts DROP CONSTRAINT attendance_attempts_outcome,
                ADD CONSTRAINT attendance_attempts_outcome CHECK (
                    (outcome = 'accepted' AND rejection_reason IS NULL
                        AND num_nonnulls(geofence_id, fix_captured_at, distance_m, accuracy_m) = 4)
                    OR (outcome = 'rejected' AND rejection_reason ~ '^[a-z][a-z0-9_]{0,63}$'
                        AND rejection_reason IS NOT NULL)
                );
            CREATE UNIQUE INDEX attendance_daily_marks ON attendance_attempts (actor_id, business_date, kind)
                WHERE assignment_id IS NULL AND outcome = 'accepted';
            CREATE INDEX attendance_actor_day_marks ON attendance_attempts (actor_id, business_date)
                WHERE outcome = 'accepted';

            CREATE OR REPLACE FUNCTION attendance_validate_mark() RETURNS trigger LANGUAGE plpgsql AS $body$
            DECLARE
                assignment attendance_assignments%ROWTYPE;
                fence attendance_geofences%ROWTYPE;
                policy attendance_policies%ROWTYPE;
                site bigint;
                tested_distance numeric;
            BEGIN
                IF NEW.outcome <> 'accepted' THEN
                    RETURN NEW;
                END IF;
                IF NEW.assignment_id IS NOT NULL THEN
                    SELECT * INTO assignment FROM attendance_assignments
                        WHERE id_assignment = NEW.assignment_id FOR SHARE;
                    IF NOT FOUND OR assignment.user_id <> NEW.actor_id
                        OR NEW.received_at < assignment.starts_at OR NEW.received_at > assignment.ends_at
                        OR (assignment.cancelled_at IS NOT NULL AND NEW.received_at >= assignment.cancelled_at) THEN
                        RAISE EXCEPTION 'Invalid attendance assignment for actor or time' USING ERRCODE = '23514';
                    END IF;
                    site := assignment.site_id;
                ELSE
                    SELECT id_site INTO site FROM attendance_sites WHERE code = 'PRINCIPAL';
                END IF;
                SELECT * INTO fence FROM attendance_geofences
                    WHERE site_id = site AND valid_from <= NEW.received_at
                    ORDER BY valid_from DESC LIMIT 1;
                IF NOT FOUND OR fence.id_geofence IS DISTINCT FROM NEW.geofence_id THEN
                    RAISE EXCEPTION 'Invalid attendance geofence version for site or time' USING ERRCODE = '23514';
                END IF;
                SELECT * INTO STRICT policy FROM attendance_policies WHERE id_policy = fence.policy_id;
                tested_distance := NEW.distance_m;
                IF policy.edge_rule = 'accuracy_within' THEN
                    tested_distance := tested_distance + NEW.accuracy_m;
                END IF;
                IF (NEW.assignment_id IS NULL AND (NEW.distance_m > 50 OR NEW.accuracy_m > 30))
                    OR NEW.fix_captured_at > NEW.received_at
                    OR NEW.received_at - NEW.fix_captured_at > make_interval(secs => policy.max_fix_age_seconds)
                    OR NEW.accuracy_m > policy.max_accuracy_m OR tested_distance > fence.radius_m THEN
                    RAISE EXCEPTION 'Attendance data fails the selected policy' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $body$;
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Restaurar el respaldo anterior para conservar el historial de asistencia diaria.');
    }
};
