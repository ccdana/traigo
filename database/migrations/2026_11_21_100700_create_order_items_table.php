<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Detalle de cada pedido. Guarda una copia del ITEM, nombre, precio y costo
     * al momento de la venta para que el historial no cambie si cambia el producto.
     */
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id_order_item')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('order_id')->constrained('orders', 'id_order')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products', 'id_product');
            $table->text('item_code');
            $table->text('product_name');
            $table->rawColumn('price_type', 'price_type')->default('unit');
            $table->decimal('unit_price_bs', 12, 2);
            $table->decimal('unit_cost_bs', 12, 2)->nullable();
            $table->integer('quantity');
            $table->decimal('line_total_bs', 12, 2)->storedAs('unit_price_bs * quantity');

            $table->unique(['order_id', 'product_id']);
        });

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_unit_price_bs_check CHECK (unit_price_bs >= 0)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_unit_cost_bs_check CHECK (unit_cost_bs >= 0)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_quantity_check CHECK (quantity > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
