<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cierre semanal de Jime y reparto del bono.
     *
     * La base impide marcar «autorizado» si no se cumple lo que el Excel llama
     * «LISTO PARA PAGO»: los 4 controles, los 6 integrantes ya definidos (cumple
     * o no cumple, nadie pendiente), el reparto sumando 100 % y la autorización
     * de Dirección. Después de autorizado, los montos no se cambian.
     *
     * Al cerrar la semana, Jime puede cerrar como «no cumple» a quien siga
     * pendiente, escribiendo el motivo. Solo Jime, y solo a quien esté pendiente.
     */
    public function up(): void
    {
        Schema::create('weekly_closings', function (Blueprint $table) {
            $table->foreignUuid('week_id')->primary()->constrained('bonus_weeks', 'id_bonus_week');
            $table->rawColumn('status', 'week_closing_status')->default('open');
            $table->boolean('payments_reconciled')->default(false);
            $table->boolean('kpi_evidence_ok')->default(false);
            $table->boolean('duplicates_cleared')->default(false);
            $table->boolean('direction_approved')->default(false);
            $table->text('observations')->nullable();
            // Foto del resultado al enviar a Dirección.
            $table->decimal('collected_bs', 12, 2)->nullable();
            $table->decimal('achievement_pct', 7, 2)->nullable();
            $table->rawColumn('tier', 'bonus_tier')->nullable();
            $table->decimal('base_prize_bs', 12, 2)->nullable();
            $table->decimal('release_factor', 4, 2)->nullable();
            $table->decimal('fund_bs', 12, 2)->nullable();
            $table->foreignUuid('sent_by')->nullable()->constrained('users', 'id_user');
            $table->timestampTz('sent_at')->nullable();
            $table->foreignUuid('authorized_by')->nullable()->constrained('users', 'id_user');
            $table->timestampTz('authorized_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('ALTER TABLE weekly_closings ADD CONSTRAINT closings_direction_chk CHECK (NOT direction_approved OR (authorized_by IS NOT NULL AND authorized_at IS NOT NULL))');
        DB::statement(<<<'SQL'
            ALTER TABLE weekly_closings ADD CONSTRAINT closings_ready_chk CHECK (
                status NOT IN ('authorized', 'paid')
                OR (payments_reconciled AND kpi_evidence_ok AND duplicates_cleared AND direction_approved
                    AND collected_bs IS NOT NULL AND tier IS NOT NULL AND fund_bs IS NOT NULL)
            )
            SQL);
        DB::statement("ALTER TABLE weekly_closings ADD CONSTRAINT closings_sent_chk CHECK (status = 'open' OR (sent_by IS NOT NULL AND sent_at IS NOT NULL))");
        DB::statement("ALTER TABLE weekly_closings ADD CONSTRAINT closings_returned_chk CHECK (status <> 'returned' OR return_reason IS NOT NULL)");
        DB::statement("ALTER TABLE weekly_closings ADD CONSTRAINT closings_paid_chk CHECK (status <> 'paid' OR paid_at IS NOT NULL)");
        DB::statement('ALTER TABLE weekly_closings ADD CONSTRAINT closings_not_own_chk CHECK (authorized_by <> sent_by)');

        Schema::create('weekly_member_results', function (Blueprint $table) {
            $table->foreignUuid('week_id')->constrained('weekly_closings', 'week_id')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users', 'id_user');
            $table->decimal('share_pct', 5, 2);
            $table->decimal('min_kpi_pct', 5, 2);
            $table->decimal('score_pct', 7, 2)->nullable();
            $table->rawColumn('result', 'compliance_result')->default('pending');
            $table->decimal('amount_bs', 12, 2)->default(0);
            $table->text('note')->nullable();
            // Cierre a mano de quien seguía pendiente.
            $table->foreignUuid('closed_by')->nullable()->constrained('users', 'id_user');
            $table->timestampTz('closed_at')->nullable();
            $table->text('close_reason')->nullable();
            $table->timestampTz('paid_at')->nullable();

            $table->primary(['week_id', 'user_id']);
        });

        // Quien no cumple o está pendiente no cobra, y su parte no se reparte.
        DB::statement("ALTER TABLE weekly_member_results ADD CONSTRAINT member_results_amount_chk CHECK (amount_bs >= 0 AND (result = 'passes' OR amount_bs = 0))");
        DB::statement("ALTER TABLE weekly_member_results ADD CONSTRAINT member_results_paid_chk CHECK (paid_at IS NULL OR result = 'passes')");
        DB::statement('ALTER TABLE weekly_member_results ADD CONSTRAINT member_results_close_complete_chk CHECK ((closed_by IS NULL) = (closed_at IS NULL) AND (closed_by IS NULL) = (close_reason IS NULL))');
        DB::statement("ALTER TABLE weekly_member_results ADD CONSTRAINT member_results_close_fails_chk CHECK (closed_by IS NULL OR (result = 'fails' AND btrim(close_reason) <> ''))");
        DB::statement('ALTER TABLE weekly_member_results ADD CONSTRAINT member_results_not_own_close_chk CHECK (closed_by <> user_id)');

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION weekly_closing_ready_to_pay() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                expected integer;
                resolved integer;
                shares numeric;
                payable numeric;
            BEGIN
                IF NEW.status NOT IN ('authorized', 'paid') THEN
                    RETURN NEW;
                END IF;

                SELECT count(*) INTO expected FROM team_profiles WHERE bonus_share_pct > 0;
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

            CREATE TRIGGER weekly_closings_ready_to_pay
                BEFORE INSERT OR UPDATE ON weekly_closings
                FOR EACH ROW EXECUTE FUNCTION weekly_closing_ready_to_pay();

            CREATE FUNCTION freeze_authorized_member_results() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                week uuid := CASE WHEN TG_OP = 'DELETE' THEN OLD.week_id ELSE NEW.week_id END;
            BEGIN
                IF EXISTS (SELECT 1 FROM weekly_closings WHERE week_id = week AND status IN ('authorized', 'paid')) THEN
                    -- Lo único permitido es marcar el pago.
                    IF TG_OP = 'UPDATE' AND to_jsonb(NEW) - 'paid_at' = to_jsonb(OLD) - 'paid_at' THEN
                        RETURN NEW;
                    END IF;
                    RAISE EXCEPTION 'El bono de esta semana ya está autorizado: no se puede cambiar.'
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END $$;

            CREATE TRIGGER weekly_member_results_frozen
                BEFORE INSERT OR UPDATE OR DELETE ON weekly_member_results
                FOR EACH ROW EXECUTE FUNCTION freeze_authorized_member_results();

            CREATE FUNCTION validate_manual_close() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.closed_by IS NULL THEN
                    RETURN NEW;
                END IF;
                -- Una fila ya cerrada no se vuelve a validar (por ejemplo, al editar la nota).
                IF TG_OP = 'UPDATE' AND OLD.closed_by IS NOT DISTINCT FROM NEW.closed_by THEN
                    RETURN NEW;
                END IF;
                IF NOT EXISTS (SELECT 1 FROM users WHERE id_user = NEW.closed_by AND role = 'control') THEN
                    RAISE EXCEPTION 'Solo Jime puede cerrar a un integrante pendiente.' USING ERRCODE = 'check_violation';
                END IF;
                -- Según los datos cargados, sin contar cierres a mano. La vista se crea
                -- en la migración de vistas.
                IF NOT EXISTS (
                    SELECT 1 FROM v_member_week_compliance_raw
                    WHERE week_id = NEW.week_id AND user_id = NEW.user_id AND result = 'pending'
                ) THEN
                    RAISE EXCEPTION 'Solo se puede cerrar a mano a quien está pendiente.' USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END $$;

            CREATE TRIGGER weekly_member_results_manual_close
                BEFORE INSERT OR UPDATE ON weekly_member_results
                FOR EACH ROW EXECUTE FUNCTION validate_manual_close();
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('weekly_member_results');
        Schema::dropIfExists('weekly_closings');
        DB::statement('DROP FUNCTION IF EXISTS validate_manual_close()');
        DB::statement('DROP FUNCTION IF EXISTS freeze_authorized_member_results()');
        DB::statement('DROP FUNCTION IF EXISTS weekly_closing_ready_to_pay()');
    }
};
