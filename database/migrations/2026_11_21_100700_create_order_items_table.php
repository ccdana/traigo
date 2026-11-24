<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Detalle de cada pedido. Cada línea es un producto o un combo, nunca ambos.
     *
     * Guarda una copia del ITEM, nombre, precio y costo al momento de la venta para
     * que el historial no cambie si cambia el catálogo. Si se aplicó una promoción,
     * guarda cuál fue y el precio de lista antes del descuento.
     */
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id_order_item')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('order_id')->constrained('orders', 'id_order')->cascadeOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained('products', 'id_product');
            $table->foreignUuid('combo_id')->nullable()->constrained('combos', 'id_combo');
            $table->foreignUuid('promotion_id')->nullable()->constrained('promotions', 'id_promotion');
            $table->text('item_code');
            $table->text('product_name');
            $table->rawColumn('price_type', 'price_type')->default('unit');
            $table->decimal('list_price_bs', 12, 2)->nullable();
            $table->decimal('unit_price_bs', 12, 2);
            $table->decimal('unit_cost_bs', 12, 2)->nullable();
            $table->integer('quantity');
            $table->decimal('line_total_bs', 12, 2)->storedAs('unit_price_bs * quantity');

            $table->unique(['order_id', 'product_id']);
            $table->unique(['order_id', 'combo_id']);
        });

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_unit_price_bs_check CHECK (unit_price_bs >= 0)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_unit_cost_bs_check CHECK (unit_cost_bs >= 0)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_quantity_check CHECK (quantity > 0)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_product_or_combo_chk CHECK (num_nonnulls(product_id, combo_id) = 1)');
        DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_items_combo_price_type_chk CHECK ((combo_id IS NOT NULL) = (price_type = 'combo'))");
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_promotion_chk CHECK (promotion_id IS NULL OR (product_id IS NOT NULL AND list_price_bs IS NOT NULL))');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_list_price_chk CHECK (list_price_bs IS NULL OR list_price_bs >= unit_price_bs)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
