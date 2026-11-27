<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE attendance_sites (
                id_site uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                code text NOT NULL UNIQUE CHECK (btrim(code) <> ''),
                name text NOT NULL CHECK (btrim(name) <> ''),
                time_zone text NOT NULL DEFAULT 'America/La_Paz' CHECK (btrim(time_zone) <> '')
            );

            -- Una política publicada conserva los parámetros que se aplicaron a una marca.
            CREATE TABLE attendance_policies (
                id_policy uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                max_accuracy_m numeric(10, 3) NOT NULL CHECK (max_accuracy_m BETWEEN 0.001 AND 9999999.999),
                max_fix_age_seconds integer NOT NULL CHECK (max_fix_age_seconds > 0),
                edge_rule text NOT NULL CHECK (edge_rule IN ('center_within', 'accuracy_within')),
                created_by uuid NOT NULL REFERENCES users (id_user),
                created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
            );

            -- La versión vigente es la última cuyo valid_from no supera la recepción.
            CREATE TABLE attendance_geofences (
                id_geofence uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                site_id uuid NOT NULL REFERENCES attendance_sites (id_site),
                policy_id uuid NOT NULL REFERENCES attendance_policies (id_policy),
                center_latitude numeric(9, 6) NOT NULL CHECK (center_latitude BETWEEN -90 AND 90),
                center_longitude numeric(9, 6) NOT NULL CHECK (center_longitude BETWEEN -180 AND 180),
                radius_m numeric(10, 3) NOT NULL CHECK (radius_m BETWEEN 0.001 AND 9999999.999),
                valid_from timestamptz NOT NULL CHECK (isfinite(valid_from)),
                created_by uuid NOT NULL REFERENCES users (id_user),
                created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (site_id, valid_from)
            );

            CREATE TABLE attendance_assignments (
                id_assignment uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                user_id uuid NOT NULL REFERENCES users (id_user),
                site_id uuid NOT NULL REFERENCES attendance_sites (id_site),
                starts_at timestamptz NOT NULL CHECK (isfinite(starts_at)),
                ends_at timestamptz NOT NULL CHECK (isfinite(ends_at) AND ends_at > starts_at),
                cancelled_at timestamptz CHECK (isfinite(cancelled_at)),
                created_by uuid NOT NULL REFERENCES users (id_user),
                created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
            );

            CREATE UNIQUE INDEX attendance_assignments_active_key
                ON attendance_assignments (user_id, site_id, starts_at, ends_at)
                WHERE cancelled_at IS NULL;

            CREATE TABLE attendance_attempts (
                id_attempt uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                actor_id uuid NOT NULL REFERENCES users (id_user),
                client_uuid uuid NOT NULL,
                assignment_id uuid REFERENCES attendance_assignments (id_assignment),
                geofence_id uuid REFERENCES attendance_geofences (id_geofence),
                kind text NOT NULL CHECK (kind IN ('entry', 'exit')),
                received_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP CHECK (isfinite(received_at)),
                fix_captured_at timestamptz CHECK (isfinite(fix_captured_at)),
                distance_m numeric(10, 3) CHECK (distance_m BETWEEN 0 AND 9999999.999),
                accuracy_m numeric(10, 3) CHECK (accuracy_m BETWEEN 0.001 AND 9999999.999),
                outcome text NOT NULL CHECK (outcome IN ('accepted', 'rejected')),
                rejection_reason text,
                UNIQUE (actor_id, client_uuid),
                CONSTRAINT attendance_attempts_outcome CHECK (
                    (outcome = 'accepted' AND rejection_reason IS NULL
                        AND num_nonnulls(assignment_id, geofence_id, fix_captured_at, distance_m, accuracy_m) = 5)
                    OR (outcome = 'rejected' AND rejection_reason IS NOT NULL
                        AND rejection_reason ~ '^[a-z][a-z0-9_]{0,63}$')
                )
            );

            CREATE UNIQUE INDEX attendance_attempts_one_mark
                ON attendance_attempts (assignment_id, kind) WHERE outcome = 'accepted';
            CREATE INDEX attendance_attempts_actor_history ON attendance_attempts (actor_id, received_at DESC);
            CREATE INDEX attendance_attempts_assignment ON attendance_attempts (assignment_id);

            -- migrate:fresh elimina las tablas, pero puede dejar funciones de PostgreSQL.
            CREATE OR REPLACE FUNCTION attendance_validate_mark() RETURNS trigger LANGUAGE plpgsql AS $body$
            DECLARE
                assignment attendance_assignments%ROWTYPE;
                fence attendance_geofences%ROWTYPE;
                policy attendance_policies%ROWTYPE;
                tested_distance numeric;
            BEGIN
                IF NEW.outcome <> 'accepted' THEN
                    RETURN NEW;
                END IF;

                SELECT * INTO assignment FROM attendance_assignments
                    WHERE id_assignment = NEW.assignment_id FOR SHARE;
                IF NOT FOUND OR assignment.user_id <> NEW.actor_id
                    OR NEW.received_at < assignment.starts_at OR NEW.received_at > assignment.ends_at
                    OR (assignment.cancelled_at IS NOT NULL AND NEW.received_at >= assignment.cancelled_at) THEN
                    RAISE EXCEPTION 'Invalid attendance assignment for actor or time' USING ERRCODE = '23514';
                END IF;

                SELECT * INTO fence FROM attendance_geofences
                    WHERE site_id = assignment.site_id AND valid_from <= NEW.received_at
                    ORDER BY valid_from DESC LIMIT 1;
                IF NOT FOUND OR fence.id_geofence IS DISTINCT FROM NEW.geofence_id THEN
                    RAISE EXCEPTION 'Invalid attendance geofence version for site or time' USING ERRCODE = '23514';
                END IF;

                SELECT * INTO STRICT policy FROM attendance_policies WHERE id_policy = fence.policy_id;
                tested_distance := NEW.distance_m;
                IF policy.edge_rule = 'accuracy_within' THEN
                    tested_distance := tested_distance + NEW.accuracy_m;
                END IF;
                IF NEW.fix_captured_at > NEW.received_at
                    OR NEW.received_at - NEW.fix_captured_at > make_interval(secs => policy.max_fix_age_seconds)
                    OR NEW.accuracy_m > policy.max_accuracy_m OR tested_distance > fence.radius_m THEN
                    RAISE EXCEPTION 'Attendance data fails the selected policy' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $body$;

            CREATE TRIGGER attendance_attempts_validate_mark
                BEFORE INSERT ON attendance_attempts FOR EACH ROW EXECUTE FUNCTION attendance_validate_mark();

            CREATE OR REPLACE FUNCTION attendance_protect_history() RETURNS trigger LANGUAGE plpgsql AS $body$
            BEGIN
                IF TG_TABLE_NAME = 'attendance_assignments' AND TG_OP = 'UPDATE' THEN
                    IF OLD.cancelled_at IS NULL AND NEW.cancelled_at IS NOT NULL
                        AND (to_jsonb(NEW) - 'cancelled_at') = (to_jsonb(OLD) - 'cancelled_at') THEN
                        RETURN NEW;
                    END IF;
                END IF;
                RAISE EXCEPTION 'Published attendance history cannot be replaced' USING ERRCODE = '23514';
            END;
            $body$;

            CREATE TRIGGER attendance_assignments_protect BEFORE UPDATE OR DELETE ON attendance_assignments
                FOR EACH ROW EXECUTE FUNCTION attendance_protect_history();
            CREATE TRIGGER attendance_geofences_immutable BEFORE UPDATE OR DELETE ON attendance_geofences
                FOR EACH ROW EXECUTE FUNCTION attendance_protect_history();
            CREATE TRIGGER attendance_policies_immutable BEFORE UPDATE OR DELETE ON attendance_policies
                FOR EACH ROW EXECUTE FUNCTION attendance_protect_history();
            CREATE TRIGGER attendance_attempts_immutable BEFORE UPDATE ON attendance_attempts
                FOR EACH ROW EXECUTE FUNCTION attendance_protect_history();
            CREATE TRIGGER attendance_accepted_attempts_protect BEFORE DELETE ON attendance_attempts
                FOR EACH ROW WHEN (OLD.outcome = 'accepted') EXECUTE FUNCTION attendance_protect_history();

            -- Una marcación es una aceptación. La vista no mantiene una segunda copia.
            CREATE VIEW attendance_marks AS
            SELECT id_attempt AS id_mark, assignment_id, geofence_id, kind, received_at AS marked_at
            FROM attendance_attempts WHERE outcome = 'accepted';
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            LOCK TABLE attendance_attempts, attendance_assignments, attendance_geofences,
                attendance_policies, attendance_sites IN ACCESS EXCLUSIVE MODE;
            DO $body$
            BEGIN
                IF EXISTS (SELECT 1 FROM attendance_attempts)
                    OR EXISTS (SELECT 1 FROM attendance_assignments)
                    OR EXISTS (SELECT 1 FROM attendance_geofences)
                    OR EXISTS (SELECT 1 FROM attendance_policies)
                    OR EXISTS (SELECT 1 FROM attendance_sites) THEN
                    RAISE EXCEPTION 'Retain attendance data before removing its tables';
                END IF;
            END;
            $body$;
            DROP VIEW attendance_marks;
            DROP TABLE attendance_attempts, attendance_assignments, attendance_geofences, attendance_policies, attendance_sites;
            DROP FUNCTION attendance_validate_mark(), attendance_protect_history();
            SQL);
    }
};
