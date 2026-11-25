<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stock por producto, conteos físicos y kardex.
     */
    public function up(): void
    {
        // Físico (stock), apartado por pedidos (reserved) y disponible.
        Schema::create('inventory', function (Blueprint $table) {
            $table->foreignUuid('product_id')->primary()->constrained('products', 'id_product')->cascadeOnDelete();
            $table->integer('stock')->default(0);
            $table->integer('reserved')->default(0);
            $table->integer('available')->storedAs('stock - reserved');
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('ALTER TABLE inventory ADD CONSTRAINT inventory_stock_chk CHECK (stock >= 0)');
        DB::statement('ALTER TABLE inventory ADD CONSTRAINT inventory_reserved_chk CHECK (reserved >= 0)');
        DB::statement('ALTER TABLE inventory ADD CONSTRAINT inventory_reserved_le_chk CHECK (reserved <= stock)');

        // Conteo físico contra sistema (KPI «Exactitud del inventario»).
        Schema::create('inventory_counts', function (Blueprint $table) {
            $table->uuid('id_inventory_count')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->date('counted_on');
            $table->foreignUuid('counted_by')->constrained('users', 'id_user');
            $table->text('note')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('inventory_count_items', function (Blueprint $table) {
            $table->foreignUuid('inventory_count_id')->constrained('inventory_counts', 'id_inventory_count')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products', 'id_product');
            $table->integer('system_qty');
            $table->integer('counted_qty');
            $table->integer('difference')->storedAs('counted_qty - system_qty');
            $table->text('note')->nullable();

            $table->primary(['inventory_count_id', 'product_id']);
        });

        DB::statement('ALTER TABLE inventory_count_items ADD CONSTRAINT count_items_qty_chk CHECK (system_qty >= 0 AND counted_qty >= 0)');

        // Kardex: la cantidad lleva signo según el tipo. No se edita ni se borra;
        // un error se corrige con otro movimiento.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->bigInteger('id_stock_movement')->generatedAs()->always()->primary();
            $table->foreignUuid('product_id')->index()->constrained('products', 'id_product');
            $table->rawColumn('type', 'movement_type');
            $table->integer('quantity');
            $table->foreignUuid('order_id')->nullable()->index()->constrained('orders', 'id_order');
            $table->foreignUuid('inventory_count_id')->nullable()->constrained('inventory_counts', 'id_inventory_count');
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
                OR (type IN ('sale', 'adjustment_out') AND quantity < 0)
            )
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('inventory_count_items');
        Schema::dropIfExists('inventory_counts');
        Schema::dropIfExists('inventory');
    }
};
