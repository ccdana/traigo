<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Comisión individual: una persona por venta, tasa de cada producto,
     * liquidación mensual. Es independiente del bono semanal.
     */
    public function up(): void
    {
        // Liquidación de un mes: Jime la envía y Dirección la autoriza.
        Schema::create('commission_settlements', function (Blueprint $table) {
            $table->uuid('id_commission_settlement')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->date('period')->unique();
            $table->rawColumn('status', 'settlement_status')->default('open');
            $table->foreignUuid('sent_by')->nullable()->constrained('users', 'id_user');
            $table->timestampTz('sent_at')->nullable();
            $table->foreignUuid('authorized_by')->nullable()->constrained('users', 'id_user');
            $table->timestampTz('authorized_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('ALTER TABLE commission_settlements ADD CONSTRAINT settlements_first_day_chk CHECK (EXTRACT(DAY FROM period) = 1)');
        DB::statement("ALTER TABLE commission_settlements ADD CONSTRAINT settlements_authorized_chk CHECK (status NOT IN ('authorized', 'paid') OR (authorized_by IS NOT NULL AND authorized_at IS NOT NULL))");
        DB::statement("ALTER TABLE commission_settlements ADD CONSTRAINT settlements_paid_chk CHECK (status <> 'paid' OR paid_at IS NOT NULL)");
        DB::statement('ALTER TABLE commission_settlements ADD CONSTRAINT settlements_not_own_chk CHECK (authorized_by <> sent_by)');

        // Una fila por línea vendida, con la tasa que tenía el producto al vender.
        // rate_pct y amount_bs nulos = porcentaje por definir (no se puede liquidar).
        // pending: venta sin cobrar. earned: cobrada. cancelled: anulada sin cobrar.
        // Una devolución de algo ya cobrado es un «adjustment» con base negativa.
        Schema::create('commissions', function (Blueprint $table) {
            $table->uuid('id_commission')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('order_id');
            $table->uuid('order_item_id')->nullable();
            $table->uuid('seller_id');
            $table->rawColumn('kind', 'commission_kind')->default('sale');
            $table->rawColumn('status', 'commission_status')->default('pending');
            $table->decimal('base_bs', 12, 2);
            $table->decimal('rate_pct', 5, 2)->nullable();
            $table->decimal('amount_bs', 12, 2)->nullable();
            $table->timestampTz('earned_at')->nullable();
            $table->foreignUuid('settlement_id')->nullable()->index()->constrained('commission_settlements', 'id_commission_settlement');
            $table->text('note')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users', 'id_user');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            // Solo puede cobrarla quien hizo la venta, y la línea es de ese pedido.
            $table->foreign(['order_id', 'seller_id'])->references(['id_order', 'seller_id'])->on('orders');
            $table->foreign(['order_item_id', 'order_id'])->references(['id_order_item', 'order_id'])->on('order_items');
            $table->index(['seller_id', 'status']);
            $table->index('order_id');
        });

        DB::statement('ALTER TABLE commissions ADD CONSTRAINT commissions_rate_pct_check CHECK (rate_pct BETWEEN 0 AND 100)');
        DB::statement(<<<'SQL'
            ALTER TABLE commissions ADD CONSTRAINT commission_amount_chk CHECK (
                (rate_pct IS NULL AND amount_bs IS NULL)
                OR (rate_pct IS NOT NULL AND amount_bs IS NOT NULL AND amount_bs = round(base_bs * rate_pct / 100, 2))
            )
            SQL);
        DB::statement("ALTER TABLE commissions ADD CONSTRAINT commission_sale_chk CHECK (kind <> 'sale' OR (order_item_id IS NOT NULL AND base_bs >= 0))");
        DB::statement("ALTER TABLE commissions ADD CONSTRAINT commission_earned_chk CHECK (status <> 'earned' OR earned_at IS NOT NULL)");
        DB::statement("ALTER TABLE commissions ADD CONSTRAINT commission_settle_chk CHECK (settlement_id IS NULL OR (status = 'earned' AND rate_pct IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX commissions_one_sale_per_item ON commissions (order_item_id) WHERE kind = 'sale'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commissions');
        Schema::dropIfExists('commission_settlements');
    }
};
