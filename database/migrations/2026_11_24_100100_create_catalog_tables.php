<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catálogo único por SKU para la app y la tienda: precio por unidad y por
     * mayor, mínimo mayorista por código, comisión por producto y costo.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id_category')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('parent_id')->nullable();
            $table->text('name');
            $table->text('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id_category')->on('categories')->nullOnDelete();
        });

        // El precio por mayor no existe (precio y mínimo nulos) o existe completo:
        // desde 2 unidades (los Excel usan 24, 12, 6 y 3) y nunca más caro que el
        // precio por unidad. commission_pct nulo = porcentaje todavía por definir.
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id_product')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->text('item_code')->unique();
            $table->foreignUuid('category_id')->nullable()->index()->constrained('categories', 'id_category')->nullOnDelete();
            $table->text('name');
            $table->text('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('price_unit_bs', 12, 2);
            $table->decimal('price_wholesale_bs', 12, 2)->nullable();
            $table->integer('wholesale_min_qty')->nullable();
            $table->decimal('commission_pct', 5, 2)->nullable();
            $table->decimal('cost_bs', 12, 2)->nullable();
            $table->rawColumn('gift_audience', 'gift_audience')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('ALTER TABLE products ADD CONSTRAINT products_price_unit_bs_check CHECK (price_unit_bs >= 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_price_wholesale_bs_check CHECK (price_wholesale_bs >= 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_cost_bs_check CHECK (cost_bs >= 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_commission_pct_check CHECK (commission_pct BETWEEN 0 AND 100)');
        DB::statement(<<<'SQL'
            ALTER TABLE products ADD CONSTRAINT wholesale_chk CHECK (
                (price_wholesale_bs IS NULL AND wholesale_min_qty IS NULL)
                OR (price_wholesale_bs IS NOT NULL
                    AND wholesale_min_qty IS NOT NULL
                    AND wholesale_min_qty >= 2
                    AND price_wholesale_bs <= price_unit_bs)
            )
            SQL);

        Schema::create('product_attributes', function (Blueprint $table) {
            $table->uuid('id_product_attribute')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('product_id')->constrained('products', 'id_product')->cascadeOnDelete();
            $table->text('name');
            $table->text('value');

            $table->unique(['product_id', 'name']);
        });

        // Imágenes guardadas en Cloudinary; position 0 es la principal.
        Schema::create('product_images', function (Blueprint $table) {
            $table->uuid('id_product_image')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('product_id')->index()->constrained('products', 'id_product')->cascadeOnDelete();
            $table->text('public_id')->unique();
            $table->text('url');
            $table->integer('position')->default(0);
            $table->timestampTz('created_at')->useCurrent();
        });

        // Jime propone un cambio de precio, mínimo, comisión o costo y solo vale
        // cuando Dirección lo aprueba. Un valor nulo significa «sin cambio».
        // Las ventas ya registradas conservan su precio (copiado en order_items).
        Schema::create('price_change_requests', function (Blueprint $table) {
            $table->uuid('id_price_change_request')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('product_id')->index()->constrained('products', 'id_product');
            $table->rawColumn('status', 'approval_status')->default('pending');
            $table->decimal('price_unit_bs', 12, 2)->nullable();
            $table->decimal('price_wholesale_bs', 12, 2)->nullable();
            $table->integer('wholesale_min_qty')->nullable();
            $table->decimal('commission_pct', 5, 2)->nullable();
            $table->decimal('cost_bs', 12, 2)->nullable();
            $table->jsonb('previous_values');
            $table->text('reason')->nullable();
            $table->foreignUuid('requested_by')->constrained('users', 'id_user');
            $table->timestampTz('requested_at')->useCurrent();
            $table->foreignUuid('decided_by')->nullable()->constrained('users', 'id_user');
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
        });

        DB::statement('ALTER TABLE price_change_requests ADD CONSTRAINT price_change_something_chk CHECK (num_nonnulls(price_unit_bs, price_wholesale_bs, wholesale_min_qty, commission_pct, cost_bs) >= 1)');
        DB::statement("ALTER TABLE price_change_requests ADD CONSTRAINT price_change_decision_chk CHECK ((status = 'pending') = (decided_by IS NULL) AND (decided_by IS NULL) = (decided_at IS NULL))");
        DB::statement('ALTER TABLE price_change_requests ADD CONSTRAINT price_change_not_own_chk CHECK (decided_by <> requested_by)');
        DB::statement("CREATE UNIQUE INDEX price_change_one_pending_per_product ON price_change_requests (product_id) WHERE status = 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_change_requests');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_attributes');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
