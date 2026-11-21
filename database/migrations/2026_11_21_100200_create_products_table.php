<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catálogo de productos con su ITEM, precio unitario, precio por mayor y costo.
     *
     * El precio por mayor no existe (precio y mínimo nulos) o existe completo:
     * mínimo de 12 unidades y nunca más caro que el precio unitario.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id_product')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->text('item_code')->unique();
            $table->foreignUuid('category_id')->nullable()->constrained('categories', 'id_category')->nullOnDelete();
            $table->text('name');
            $table->text('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('price_unit_bs', 12, 2);
            $table->decimal('price_wholesale_bs', 12, 2)->nullable();
            $table->integer('wholesale_min_qty')->nullable();
            $table->decimal('cost_bs', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('ALTER TABLE products ADD CONSTRAINT products_price_unit_bs_check CHECK (price_unit_bs >= 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_price_wholesale_bs_check CHECK (price_wholesale_bs >= 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_cost_bs_check CHECK (cost_bs >= 0)');
        DB::statement(<<<'SQL'
            ALTER TABLE products ADD CONSTRAINT wholesale_chk CHECK (
                (price_wholesale_bs IS NULL AND wholesale_min_qty IS NULL)
                OR (price_wholesale_bs IS NOT NULL
                    AND wholesale_min_qty >= 12
                    AND price_wholesale_bs <= price_unit_bs)
            )
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
