<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stock por producto: físico (stock), apartado por pedidos web (reserved)
     * y disponible, calculado por la base de datos.
     */
    public function up(): void
    {
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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory');
    }
};
