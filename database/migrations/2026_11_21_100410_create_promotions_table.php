<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Promociones con vigencia. El descuento puede ser un porcentaje, un monto fijo
     * que se resta o un precio final fijo. Por defecto no aplica sobre el precio por mayor.
     * Sin ends_at, la promoción dura hasta que se desactive.
     */
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->uuid('id_promotion')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->text('name');
            $table->text('description')->nullable();
            $table->rawColumn('discount_type', 'promotion_discount_type');
            $table->decimal('discount_value', 12, 2);
            $table->boolean('applies_to_wholesale')->default(false);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('ALTER TABLE promotions ADD CONSTRAINT promotions_discount_value_check CHECK (discount_value >= 0)');
        DB::statement("ALTER TABLE promotions ADD CONSTRAINT promotions_percentage_chk CHECK (discount_type <> 'percentage' OR discount_value <= 100)");
        DB::statement('ALTER TABLE promotions ADD CONSTRAINT promotions_dates_chk CHECK (ends_at IS NULL OR ends_at > starts_at)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
