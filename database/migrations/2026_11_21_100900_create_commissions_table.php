<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Una comisión por venta. Guarda el porcentaje usado en ese momento para que
     * las comisiones pasadas no cambien si cambia el porcentaje del vendedor.
     */
    public function up(): void
    {
        Schema::create('commissions', function (Blueprint $table) {
            $table->uuid('id_commission')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('order_id')->unique()->constrained('orders', 'id_order')->cascadeOnDelete();
            $table->foreignUuid('seller_id')->constrained('users', 'id_user');
            $table->decimal('rate_pct', 5, 2);
            $table->decimal('base_bs', 12, 2);
            $table->decimal('amount_bs', 12, 2);
            $table->rawColumn('status', 'commission_status')->default('pending');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE commissions ADD CONSTRAINT commissions_rate_pct_check CHECK (rate_pct BETWEEN 0 AND 100)');
        DB::statement('ALTER TABLE commissions ADD CONSTRAINT commissions_base_bs_check CHECK (base_bs >= 0)');
        DB::statement('ALTER TABLE commissions ADD CONSTRAINT commissions_amount_bs_check CHECK (amount_bs >= 0)');
        DB::statement('ALTER TABLE commissions ADD CONSTRAINT commission_amount_chk CHECK (amount_bs = round(base_bs * rate_pct / 100, 2))');
        DB::statement("ALTER TABLE commissions ADD CONSTRAINT commission_paid_chk CHECK (status <> 'paid' OR paid_at IS NOT NULL)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commissions');
    }
};
