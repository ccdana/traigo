<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contenido de cada combo: qué productos y cuántas unidades de cada uno.
     * Un producto usado en un combo no se puede borrar mientras siga en él.
     */
    public function up(): void
    {
        Schema::create('combo_items', function (Blueprint $table) {
            $table->foreignUuid('combo_id')->constrained('combos', 'id_combo')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products', 'id_product');
            $table->integer('quantity');

            $table->primary(['combo_id', 'product_id']);
            $table->index('product_id');
        });

        DB::statement('ALTER TABLE combo_items ADD CONSTRAINT combo_items_quantity_check CHECK (quantity > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('combo_items');
    }
};
