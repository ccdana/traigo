<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tablas cuyos cambios quedan registrados. Cada fila: [tabla, evento, clave].
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private array $audited = [
        ['products', 'UPDATE OF price_unit_bs, price_wholesale_bs, wholesale_min_qty, commission_pct, cost_bs, is_active OR DELETE', 'id_product'],
        ['price_change_requests', 'INSERT OR UPDATE OR DELETE', 'id_price_change_request'],
        ['orders', 'UPDATE OF status, origin, seller_id, total_bs OR DELETE', 'id_order'],
        ['payments', 'INSERT OR UPDATE OR DELETE', 'id_payment'],
        ['cash_closings', 'INSERT OR UPDATE OR DELETE', 'id_cash_closing'],
        ['cash_closing_lines', 'UPDATE OR DELETE', 'id_cash_closing_line'],
        ['inventory', 'UPDATE OR DELETE', 'product_id'],
        ['commissions', 'INSERT OR UPDATE OR DELETE', 'id_commission'],
        ['commission_settlements', 'INSERT OR UPDATE OR DELETE', 'id_commission_settlement'],
        ['kpi_indicators', 'UPDATE OR DELETE', 'id_kpi_indicator'],
        ['kpi_weekly_results', 'UPDATE OR DELETE', 'id_kpi_weekly_result'],
        ['kpi_evidence', 'INSERT OR UPDATE OR DELETE', 'id_kpi_evidence'],
        ['system_deliverables', 'UPDATE OR DELETE', 'id_system_deliverable'],
        ['bonus_weeks', 'UPDATE OR DELETE', 'id_bonus_week'],
        ['bonus_tiers', 'UPDATE OR DELETE', 'tier'],
        ['team_profiles', 'UPDATE OR DELETE', 'user_id'],
        ['weekly_closings', 'UPDATE OR DELETE', 'week_id'],
        ['weekly_member_results', 'INSERT OR UPDATE OR DELETE', 'week_id,user_id'],
        ['users', 'UPDATE OF role, code, is_active OR DELETE', 'id_user'],
        ['settings', 'UPDATE OR DELETE', 'key'],
    ];

    /**
     * Historial inalterable de los cambios clave: quién, cuándo, antes y después.
     * Lo escribe la propia base, así también queda lo que se cambie a mano.
     *
     * El backend debe indicar quién actúa en cada petición:
     *   select set_config('traigo.user_id', '<uuid>', false)
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigInteger('id_audit_log')->generatedAs()->always()->primary();
            $table->uuid('actor_id')->nullable()->index();
            $table->text('action');
            $table->text('entity_type');
            $table->text('entity_id');
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->text('db_user')->default(DB::raw('CURRENT_USER'));
            $table->timestampTz('created_at')->useCurrent()->index();

            $table->index(['entity_type', 'entity_id']);
        });

        DB::unprepared(<<<'SQL'
            -- Los registros del historial y del kardex no se editan ni se borran.
            CREATE OR REPLACE FUNCTION forbid_history_changes() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'La tabla % solo admite agregar filas.', TG_TABLE_NAME
                    USING ERRCODE = 'restrict_violation';
            END $$;

            CREATE TRIGGER audit_logs_append_only BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION forbid_history_changes();
            CREATE TRIGGER audit_logs_no_truncate BEFORE TRUNCATE ON audit_logs
                FOR EACH STATEMENT EXECUTE FUNCTION forbid_history_changes();
            CREATE TRIGGER stock_movements_append_only BEFORE UPDATE OR DELETE ON stock_movements
                FOR EACH ROW EXECUTE FUNCTION forbid_history_changes();

            CREATE OR REPLACE FUNCTION audit_row_change() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                secret text[] := ARRAY['password', 'pin_hash', 'remember_token'];
                before jsonb;
                after jsonb;
                key_column text;
                key_values text[] := ARRAY[]::text[];
            BEGIN
                IF TG_OP <> 'INSERT' THEN before := to_jsonb(OLD) - secret; END IF;
                IF TG_OP <> 'DELETE' THEN after := to_jsonb(NEW) - secret; END IF;
                IF TG_OP = 'UPDATE' AND before = after THEN RETURN NULL; END IF;

                FOREACH key_column IN ARRAY string_to_array(TG_ARGV[0], ',') LOOP
                    key_values := key_values || (coalesce(after, before) ->> key_column);
                END LOOP;

                INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, old_values, new_values)
                VALUES (nullif(current_setting('traigo.user_id', true), '')::uuid, lower(TG_OP),
                        TG_TABLE_NAME, array_to_string(key_values, ':'), before, after);
                RETURN NULL;
            END $$;
            SQL);

        foreach ($this->audited as [$table, $events, $key]) {
            DB::statement("CREATE TRIGGER {$table}_audit AFTER {$events} ON {$table} FOR EACH ROW EXECUTE FUNCTION audit_row_change('{$key}')");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->audited as [$table]) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_audit ON {$table}");
        }

        DB::statement('DROP TRIGGER IF EXISTS stock_movements_append_only ON stock_movements');
        Schema::dropIfExists('audit_logs');
        DB::statement('DROP FUNCTION IF EXISTS audit_row_change()');
        DB::statement('DROP FUNCTION IF EXISTS forbid_history_changes()');
    }
};
