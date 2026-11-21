<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pagos de cada pedido. Un pedido puede tener un comprobante rechazado
     * y luego otro válido; los comprobantes se guardan en Cloudinary.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id_payment')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('order_id')->constrained('orders', 'id_order')->cascadeOnDelete();
            $table->rawColumn('method', 'payment_method');
            $table->rawColumn('status', 'payment_status')->default('pending');
            $table->decimal('amount_bs', 12, 2);
            $table->text('proof_public_id')->nullable();
            $table->text('proof_url')->nullable();
            $table->text('reference')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users', 'id_user');
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('reject_reason')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_bs_check CHECK (amount_bs >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
