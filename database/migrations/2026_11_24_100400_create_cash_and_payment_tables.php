<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cobros conciliados y cierre de caja diario por método de pago.
     */
    public function up(): void
    {
        // Isaí cierra la caja del día de todo el equipo y Jime la valida.
        Schema::create('cash_closings', function (Blueprint $table) {
            $table->uuid('id_cash_closing')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->date('business_date')->unique();
            $table->rawColumn('status', 'cash_closing_status')->default('open');
            $table->foreignUuid('closed_by')->constrained('users', 'id_user');
            $table->text('note')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->foreignUuid('validated_by')->nullable()->constrained('users', 'id_user');
            $table->timestampTz('validated_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('ALTER TABLE cash_closings ADD CONSTRAINT cash_closings_not_own_chk CHECK (validated_by <> closed_by)');
        DB::statement("ALTER TABLE cash_closings ADD CONSTRAINT cash_closings_sent_chk CHECK (status = 'open' OR sent_at IS NOT NULL)");
        DB::statement("ALTER TABLE cash_closings ADD CONSTRAINT cash_closings_reviewed_chk CHECK (status NOT IN ('validated', 'observed') OR (validated_by IS NOT NULL AND validated_at IS NOT NULL))");

        Schema::create('cash_closing_lines', function (Blueprint $table) {
            $table->uuid('id_cash_closing_line')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('cash_closing_id')->constrained('cash_closings', 'id_cash_closing')->cascadeOnDelete();
            $table->rawColumn('method', 'payment_method');
            $table->integer('orders_count')->default(0);
            $table->decimal('expected_bs', 12, 2);
            $table->decimal('counted_bs', 12, 2);
            $table->decimal('difference_bs', 12, 2)->storedAs('counted_bs - expected_bs');
            $table->text('note')->nullable();

            $table->unique(['cash_closing_id', 'method']);
        });

        DB::statement('ALTER TABLE cash_closing_lines ADD CONSTRAINT cash_lines_amounts_chk CHECK (expected_bs >= 0 AND counted_bs >= 0 AND orders_count >= 0)');

        // Solo se confirma un cobro con respaldo (comprobante, pasarela o efectivo
        // recibido). received_at es cuándo llegó el dinero: define en qué semana
        // cuenta para el bono. Un cobro devuelto o anulado pasa a «reversed».
        // De las tarjetas nunca se guardan datos: solo el id de la pasarela.
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id_payment')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('order_id')->index()->constrained('orders', 'id_order');
            $table->rawColumn('method', 'payment_method');
            $table->rawColumn('status', 'payment_status')->default('pending');
            $table->decimal('amount_bs', 12, 2);
            $table->text('reference')->nullable();
            $table->text('proof_public_id')->nullable();
            $table->text('proof_url')->nullable();
            // Lo que revisa Jime antes de confirmar. Que la referencia no se
            // repita lo garantiza el índice único de abajo.
            $table->boolean('amount_matches')->nullable();
            $table->boolean('destination_ok')->nullable();
            $table->text('gateway_provider')->nullable();
            $table->text('gateway_transaction_id')->nullable();
            $table->foreignUuid('submitted_by')->nullable()->constrained('users', 'id_user');
            $table->timestampTz('submitted_at')->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users', 'id_user');
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->text('reject_reason')->nullable();
            $table->foreignUuid('reversed_by')->nullable()->constrained('users', 'id_user');
            $table->timestampTz('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->foreignUuid('cash_closing_id')->nullable()->index()->constrained('cash_closings', 'id_cash_closing');
            $table->uuid('client_uuid')->nullable()->unique();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index(['status', 'received_at']);
        });

        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_bs_check CHECK (amount_bs > 0)');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_backing_chk CHECK (status <> 'confirmed' OR method = 'cash' OR proof_url IS NOT NULL OR gateway_transaction_id IS NOT NULL)");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_confirmed_chk CHECK (status NOT IN ('confirmed', 'reversed') OR (reviewed_at IS NOT NULL AND received_at IS NOT NULL))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_rejected_chk CHECK (status <> 'rejected' OR reject_reason IS NOT NULL)");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_reversed_chk CHECK ((status = 'reversed') = (reversed_at IS NOT NULL) AND (status <> 'reversed' OR reversal_reason IS NOT NULL))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_not_own_chk CHECK (reviewed_by <> submitted_by)');
        DB::statement("CREATE UNIQUE INDEX payments_reference_unique ON payments (method, reference) WHERE reference IS NOT NULL AND status NOT IN ('rejected', 'reversed')");
        DB::statement('CREATE UNIQUE INDEX payments_gateway_unique ON payments (gateway_provider, gateway_transaction_id) WHERE gateway_transaction_id IS NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('cash_closing_lines');
        Schema::dropIfExists('cash_closings');
    }
};
