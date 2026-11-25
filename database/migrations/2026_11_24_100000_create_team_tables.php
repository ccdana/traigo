<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Segundo paso del ingreso (Jime y Dirección) y la parte de cada integrante
     * en el bono grupal.
     */
    public function up(): void
    {
        // El código de 6 números nunca se guarda en claro.
        Schema::create('two_factor_challenges', function (Blueprint $table) {
            $table->uuid('id_two_factor_challenge')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('user_id')->index()->constrained('users', 'id_user')->cascadeOnDelete();
            $table->text('code_hash');
            $table->text('channel')->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('resend_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE two_factor_challenges ADD CONSTRAINT two_factor_attempts_chk CHECK (attempts >= 0)');

        // Reparto del bono (20/20/20/20/10/10) y KPI mínimo para cobrarlo. La suma
        // de las partes debe ser 100 %; se exige al autorizar el cierre semanal.
        Schema::create('team_profiles', function (Blueprint $table) {
            $table->foreignUuid('user_id')->primary()->constrained('users', 'id_user')->cascadeOnDelete();
            $table->decimal('bonus_share_pct', 5, 2)->default(0);
            $table->decimal('min_kpi_pct', 5, 2)->nullable();
            $table->smallInteger('sort_order')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('ALTER TABLE team_profiles ADD CONSTRAINT team_profiles_share_chk CHECK (bonus_share_pct BETWEEN 0 AND 100)');
        DB::statement('ALTER TABLE team_profiles ADD CONSTRAINT team_profiles_min_chk CHECK (min_kpi_pct BETWEEN 0 AND 100)');
        DB::statement('ALTER TABLE team_profiles ADD CONSTRAINT team_profiles_share_needs_min_chk CHECK (bonus_share_pct = 0 OR min_kpi_pct IS NOT NULL)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_profiles');
        Schema::dropIfExists('two_factor_challenges');
    }
};
