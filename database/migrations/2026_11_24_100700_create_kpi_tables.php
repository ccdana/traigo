<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Semanas de la campaña, escalones del premio, KPI individual con su
     * evidencia y las entregas obligatorias de Sistemas.
     */
    public function up(): void
    {
        // count_target_factor reduce las metas de cantidad en semanas parciales
        // (0,9 la primera y 0,7 la última).
        Schema::create('bonus_weeks', function (Blueprint $table) {
            $table->uuid('id_bonus_week')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->smallInteger('number')->unique();
            $table->text('label');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('sales_target_bs', 12, 2);
            $table->decimal('base_prize_bs', 12, 2);
            $table->decimal('count_target_factor', 3, 2)->default(1);
            $table->boolean('requires_system_deliverables')->default(false);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('ALTER TABLE bonus_weeks ADD CONSTRAINT bonus_weeks_dates_chk CHECK (ends_on >= starts_on)');
        DB::statement('ALTER TABLE bonus_weeks ADD CONSTRAINT bonus_weeks_amounts_chk CHECK (sales_target_bs > 0 AND base_prize_bs >= 0)');
        DB::statement('ALTER TABLE bonus_weeks ADD CONSTRAINT bonus_weeks_factor_chk CHECK (count_target_factor > 0 AND count_target_factor <= 1)');
        DB::statement("ALTER TABLE bonus_weeks ADD CONSTRAINT bonus_weeks_no_overlap EXCLUDE USING gist (daterange(starts_on, ends_on, '[]') WITH &&)");

        // 90 % libera 50 % del premio; 100 %, 100 %; 115 %, 125 %.
        Schema::create('bonus_tiers', function (Blueprint $table) {
            $table->rawColumn('tier', 'bonus_tier')->primary();
            $table->decimal('threshold_pct', 6, 2)->unique();
            $table->decimal('release_factor', 4, 2);
        });

        DB::statement("ALTER TABLE bonus_tiers ADD CONSTRAINT bonus_tiers_chk CHECK (tier <> 'none' AND threshold_pct > 0 AND release_factor > 0)");

        // Indicadores de cada integrante: meta de una semana completa y peso. Los
        // pesos de cada persona suman 100 %. unit percent: las metas no se reducen.
        Schema::create('kpi_indicators', function (Blueprint $table) {
            $table->uuid('id_kpi_indicator')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('user_id')->constrained('users', 'id_user');
            $table->text('name');
            $table->text('how_verified');
            $table->rawColumn('unit', 'kpi_unit');
            $table->decimal('base_target', 10, 2);
            $table->decimal('weight_pct', 5, 2);
            $table->smallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['user_id', 'name']);
            $table->unique(['id_kpi_indicator', 'user_id']);
        });

        DB::statement('ALTER TABLE kpi_indicators ADD CONSTRAINT kpi_indicators_chk CHECK (base_target > 0 AND weight_pct > 0 AND weight_pct <= 100)');

        // El resultado de un indicador en una semana. target y weight_pct se copian
        // al generar la semana. actual lo carga Jime (nulo = pendiente).
        Schema::create('kpi_weekly_results', function (Blueprint $table) {
            $table->uuid('id_kpi_weekly_result')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('week_id')->constrained('bonus_weeks', 'id_bonus_week');
            $table->uuid('indicator_id');
            $table->uuid('user_id');
            $table->decimal('target', 10, 2);
            $table->decimal('weight_pct', 5, 2);
            $table->decimal('actual', 10, 2)->nullable();
            $table->foreignUuid('loaded_by')->nullable()->constrained('users', 'id_user');
            $table->timestampTz('loaded_at')->nullable();
            $table->text('note')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign(['indicator_id', 'user_id'])->references(['id_kpi_indicator', 'user_id'])->on('kpi_indicators');
            $table->unique(['week_id', 'indicator_id']);
            $table->index(['user_id', 'week_id']);
        });

        DB::statement('ALTER TABLE kpi_weekly_results ADD CONSTRAINT kpi_results_values_chk CHECK (target > 0 AND weight_pct > 0 AND actual >= 0)');
        DB::statement('ALTER TABLE kpi_weekly_results ADD CONSTRAINT kpi_results_loaded_chk CHECK ((actual IS NULL) = (loaded_by IS NULL) AND (actual IS NULL) = (loaded_at IS NULL))');
        DB::statement('ALTER TABLE kpi_weekly_results ADD CONSTRAINT kpi_results_not_own_chk CHECK (loaded_by <> user_id)');

        // Foto, enlace o pedido que respalda un indicador. Sin filas = «sin evidencia».
        Schema::create('kpi_evidence', function (Blueprint $table) {
            $table->uuid('id_kpi_evidence')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('kpi_weekly_result_id')->index()->constrained('kpi_weekly_results', 'id_kpi_weekly_result')->cascadeOnDelete();
            $table->rawColumn('kind', 'evidence_kind');
            $table->text('url')->nullable();
            $table->text('public_id')->nullable();
            $table->foreignUuid('order_id')->nullable()->constrained('orders', 'id_order');
            $table->text('note')->nullable();
            $table->rawColumn('status', 'evidence_status')->default('in_review');
            $table->foreignUuid('submitted_by')->constrained('users', 'id_user');
            $table->timestampTz('submitted_at')->useCurrent();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users', 'id_user');
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->uuid('client_uuid')->nullable()->unique();
        });

        DB::statement("ALTER TABLE kpi_evidence ADD CONSTRAINT kpi_evidence_content_chk CHECK ((kind = 'order') = (order_id IS NOT NULL) AND (kind = 'order' OR url IS NOT NULL))");
        DB::statement("ALTER TABLE kpi_evidence ADD CONSTRAINT kpi_evidence_review_chk CHECK ((status = 'in_review') = (reviewed_by IS NULL) AND (reviewed_by IS NULL) = (reviewed_at IS NULL))");
        DB::statement("ALTER TABLE kpi_evidence ADD CONSTRAINT kpi_evidence_observed_chk CHECK (status <> 'observed' OR review_note IS NOT NULL)");
        DB::statement('ALTER TABLE kpi_evidence ADD CONSTRAINT kpi_evidence_not_own_chk CHECK (reviewed_by <> submitted_by)');

        // Entregas obligatorias para el primer bono de Sistemas. «result» repite la
        // fórmula del Excel (HITOS SISTEMAS).
        Schema::create('system_deliverables', function (Blueprint $table) {
            $table->uuid('id_system_deliverable')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('week_id')->constrained('bonus_weeks', 'id_bonus_week');
            $table->foreignUuid('user_id')->constrained('users', 'id_user');
            $table->text('title');
            $table->text('acceptance_test');
            $table->date('due_on');
            $table->date('delivered_on')->nullable();
            $table->text('evidence_url')->nullable();
            $table->text('evidence_public_id')->nullable();
            $table->boolean('jime_validated')->nullable();
            $table->foreignUuid('validated_by')->nullable()->constrained('users', 'id_user');
            $table->timestampTz('validated_at')->nullable();
            $table->text('review_note')->nullable();
            $table->text('result')->storedAs(<<<'SQL'
                CASE
                    WHEN delivered_on IS NULL OR jime_validated IS NULL OR evidence_url IS NULL THEN 'pending'
                    WHEN NOT jime_validated THEN 'not_validated'
                    WHEN delivered_on <= due_on THEN 'on_time'
                    ELSE 'late'
                END
                SQL);
            $table->smallInteger('sort_order')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['user_id', 'title']);
        });

        DB::statement('ALTER TABLE system_deliverables ADD CONSTRAINT deliverables_review_chk CHECK ((jime_validated IS NULL) = (validated_by IS NULL) AND (validated_by IS NULL) = (validated_at IS NULL))');
        DB::statement('ALTER TABLE system_deliverables ADD CONSTRAINT deliverables_not_own_chk CHECK (validated_by <> user_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_deliverables');
        Schema::dropIfExists('kpi_evidence');
        Schema::dropIfExists('kpi_weekly_results');
        Schema::dropIfExists('kpi_indicators');
        Schema::dropIfExists('bonus_tiers');
        Schema::dropIfExists('bonus_weeks');
    }
};
