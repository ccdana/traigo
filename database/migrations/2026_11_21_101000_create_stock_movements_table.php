<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kardex: historial de entradas y salidas de inventario. La cantidad lleva
     * signo y debe coincidir con el tipo de movimiento (+ entradas, - salidas).
     */
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->bigInteger('id_stock_movement')->generatedAs()->always()->primary();
            $table->foreignUuid('product_id')->constrained('products', 'id_product');
            $table->rawColumn('type', 'movement_type');
            $table->integer('quantity');
            $table->foreignUuid('order_id')->nullable()->constrained('orders', 'id_order');
            $table->uuid('client_uuid')->nullable()->unique();
            $table->text('source')->default('admin');
            $table->foreignUuid('created_by')->nullable()->constrained('users', 'id_user');
            $table->text('note')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_quantity_check CHECK (quantity <> 0)');
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_source_check CHECK (source IN ('web', 'app', 'admin', 'system'))");
        DB::statement(<<<'SQL'
            ALTER TABLE stock_movements ADD CONSTRAINT movement_sign_chk CHECK (
                (type IN ('initial', 'purchase', 'sale_return', 'adjustment_in') AND quantity > 0)
                OR (type IN ('sale_web', 'sale_pos', 'adjustment_out') AND quantity < 0)
            )
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
