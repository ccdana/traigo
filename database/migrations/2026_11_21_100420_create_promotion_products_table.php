<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Productos incluidos en cada promoción (una promoción puede abarcar muchos productos).
     */
    public function up(): void
    {
        Schema::create('promotion_products', function (Blueprint $table) {
            $table->foreignUuid('promotion_id')->constrained('promotions', 'id_promotion')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products', 'id_product')->cascadeOnDelete();

            $table->primary(['promotion_id', 'product_id']);
            $table->index('product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promotion_products');
    }
};
