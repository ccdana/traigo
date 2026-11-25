<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Promociones (incluido el calendario de 24 puertas de Navidad) y combos.
     */
    public function up(): void
    {
        // El descuento puede ser un porcentaje, un monto que se resta o un precio
        // final. Solo se publica cuando Dirección la autoriza. calendar_day es la
        // puerta de diciembre (1 a 24); hay como máximo una oferta por puerta.
        // Regla que valida el backend: ninguna oferta baja del precio por mayor.
        Schema::create('promotions', function (Blueprint $table) {
            $table->uuid('id_promotion')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->text('name');
            $table->text('description')->nullable();
            $table->rawColumn('discount_type', 'promotion_discount_type');
            $table->decimal('discount_value', 12, 2);
            $table->boolean('applies_to_wholesale')->default(false);
            $table->smallInteger('calendar_day')->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->rawColumn('status', 'approval_status')->default('pending');
            $table->foreignUuid('created_by')->nullable()->constrained('users', 'id_user');
            $table->foreignUuid('authorized_by')->nullable()->constrained('users', 'id_user');
            $table->timestampTz('authorized_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('ALTER TABLE promotions ADD CONSTRAINT promotions_discount_value_check CHECK (discount_value >= 0)');
        DB::statement("ALTER TABLE promotions ADD CONSTRAINT promotions_percentage_chk CHECK (discount_type <> 'percentage' OR discount_value <= 100)");
        DB::statement('ALTER TABLE promotions ADD CONSTRAINT promotions_dates_chk CHECK (ends_at IS NULL OR ends_at > starts_at)');
        DB::statement('ALTER TABLE promotions ADD CONSTRAINT promotions_calendar_day_chk CHECK (calendar_day IS NULL OR (calendar_day BETWEEN 1 AND 24 AND ends_at IS NOT NULL))');
        DB::statement("ALTER TABLE promotions ADD CONSTRAINT promotions_authorized_chk CHECK (status <> 'approved' OR (authorized_by IS NOT NULL AND authorized_at IS NOT NULL))");
        DB::statement('ALTER TABLE promotions ADD CONSTRAINT promotions_not_own_chk CHECK (authorized_by <> created_by)');
        DB::statement("CREATE UNIQUE INDEX promotions_one_per_door ON promotions (calendar_day) WHERE calendar_day IS NOT NULL AND status <> 'rejected'");

        Schema::create('promotion_products', function (Blueprint $table) {
            $table->foreignUuid('promotion_id')->constrained('promotions', 'id_promotion')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products', 'id_product')->cascadeOnDelete();

            $table->primary(['promotion_id', 'product_id']);
            $table->index('product_id');
        });

        // Varios productos vendidos juntos a un precio propio. No tienen stock
        // propio: su disponibilidad sale del inventario de lo que contienen.
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

        Schema::create('combo_items', function (Blueprint $table) {
            $table->foreignUuid('combo_id')->constrained('combos', 'id_combo')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products', 'id_product');
            $table->integer('quantity');

            $table->primary(['combo_id', 'product_id']);
            $table->index('product_id');
        });

        DB::statement('ALTER TABLE combo_items ADD CONSTRAINT combo_items_quantity_check CHECK (quantity > 0)');

        Schema::create('combo_images', function (Blueprint $table) {
            $table->uuid('id_combo_image')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('combo_id')->index()->constrained('combos', 'id_combo')->cascadeOnDelete();
            $table->text('public_id')->unique();
            $table->text('url');
            $table->integer('position')->default(0);
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('combo_images');
        Schema::dropIfExists('combo_items');
        Schema::dropIfExists('combos');
        Schema::dropIfExists('promotion_products');
        Schema::dropIfExists('promotions');
    }
};
