<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Combos: varios productos vendidos juntos a un precio propio. No tienen stock
     * propio; su disponibilidad sale del inventario de los productos que contienen.
     * Las fechas son opcionales para combos de temporada.
     */
    public function up(): void
    {
        Schema::create('combos', function (Blueprint $table) {
            $table->uuid('id_combo')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->text('item_code')->unique();
            $table->text('name');
            $table->text('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('price_bs', 12, 2);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('ALTER TABLE combos ADD CONSTRAINT combos_price_bs_check CHECK (price_bs >= 0)');
        DB::statement('ALTER TABLE combos ADD CONSTRAINT combos_dates_chk CHECK (ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('combos');
    }
};
