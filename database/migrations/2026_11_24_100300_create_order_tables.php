<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ventas de todos los canales (presencial, WhatsApp, mayorista y web),
     * registradas una sola vez, con su vendedor y su origen. También las
     * cotizaciones por mayor que se piden desde la tienda.
     */
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE order_number_seq START 1000');

        // - seller_id: una sola persona por venta. Las ventas web no tienen.
        // - client_uuid: la app y la web lo generan para no duplicar la venta si
        //   se reintenta (se contabiliza UNA sola venta).
        // - public_token: para que un cliente sin cuenta siga su pedido; el número
        //   de pedido solo se puede adivinar y no alcanza.
        // - shipping_bs nulo: costo de envío todavía por definir.
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id_order')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->text('order_number')->unique()->default(DB::raw("('N-' || nextval('order_number_seq'))"));
            $table->rawColumn('origin', 'order_origin');
            $table->rawColumn('status', 'order_status')->default('pending_payment');
            $table->foreignUuid('customer_id')->nullable()->index()->constrained('users', 'id_user');
            $table->foreignUuid('seller_id')->nullable()->constrained('users', 'id_user');
            $table->foreignUuid('created_by')->nullable()->constrained('users', 'id_user');
            $table->text('customer_name');
            $table->text('customer_phone')->nullable();
            $table->rawColumn('delivery_type', 'delivery_type')->default('pickup');
            $table->text('shipping_address')->nullable();
            $table->decimal('shipping_bs', 12, 2)->nullable();
            $table->decimal('subtotal_bs', 12, 2)->default(0);
            $table->decimal('total_bs', 12, 2)->default(0);
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('promised_dispatch_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('ready_at')->nullable();
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->uuid('duplicate_of_id')->nullable();
            $table->text('public_token')->unique()->default(DB::raw("replace(gen_random_uuid()::text, '-', '')"));
            $table->uuid('client_uuid')->nullable()->unique();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            // Para que una comisión solo pueda ir a quien hizo la venta.
            $table->unique(['id_order', 'seller_id']);
            $table->index(['seller_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('duplicate_of_id')->references('id_order')->on('orders');
        });

        DB::statement('ALTER SEQUENCE order_number_seq OWNED BY orders.order_number');

        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_shipping_bs_check CHECK (shipping_bs >= 0)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_subtotal_bs_check CHECK (subtotal_bs >= 0)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_total_chk CHECK (total_bs = subtotal_bs + coalesce(shipping_bs, 0))');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_team_seller_chk CHECK (origin = 'web' OR seller_id IS NOT NULL)");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_delivery_chk CHECK (delivery_type = 'pickup' OR shipping_address IS NOT NULL)");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_paid_at_chk CHECK (status NOT IN ('paid', 'ready', 'delivered') OR paid_at IS NOT NULL)");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_duplicate_chk CHECK (duplicate_of_id <> id_order)');

        // Cada línea es un producto o un combo. Guarda una copia del código,
        // nombre, precios, costo y % de comisión del momento de la venta.
        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id_order_item')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('order_id')->constrained('orders', 'id_order')->cascadeOnDelete();
            $table->foreignUuid('product_id')->nullable()->index()->constrained('products', 'id_product');
            $table->foreignUuid('combo_id')->nullable()->index()->constrained('combos', 'id_combo');
            $table->foreignUuid('promotion_id')->nullable()->constrained('promotions', 'id_promotion');
            $table->text('item_code');
            $table->text('product_name');
            $table->rawColumn('price_type', 'price_type')->default('unit');
            $table->decimal('list_price_bs', 12, 2)->nullable();
            $table->decimal('unit_price_bs', 12, 2);
            $table->decimal('unit_cost_bs', 12, 2)->nullable();
            $table->decimal('commission_rate_pct', 5, 2)->nullable();
            $table->integer('quantity');
            $table->decimal('line_total_bs', 12, 2)->storedAs('unit_price_bs * quantity');

            $table->unique(['order_id', 'product_id']);
            $table->unique(['order_id', 'combo_id']);
            $table->unique(['id_order_item', 'order_id']);
        });

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_unit_price_bs_check CHECK (unit_price_bs >= 0)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_unit_cost_bs_check CHECK (unit_cost_bs >= 0)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_commission_rate_chk CHECK (commission_rate_pct BETWEEN 0 AND 100)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_quantity_check CHECK (quantity > 0)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_product_or_combo_chk CHECK (num_nonnulls(product_id, combo_id) = 1)');
        DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_items_combo_price_type_chk CHECK ((combo_id IS NOT NULL) = (price_type = 'combo'))");
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_promotion_chk CHECK (promotion_id IS NULL OR (product_id IS NOT NULL AND list_price_bs IS NOT NULL))');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_list_price_chk CHECK (list_price_bs IS NULL OR list_price_bs >= unit_price_bs)');

        // Línea de tiempo del pedido: registrado, pago confirmado, listo, entregado…
        Schema::create('order_status_history', function (Blueprint $table) {
            $table->bigInteger('id_order_status_history')->generatedAs()->always()->primary();
            $table->foreignUuid('order_id')->index()->constrained('orders', 'id_order')->cascadeOnDelete();
            $table->rawColumn('from_status', 'order_status')->nullable();
            $table->rawColumn('to_status', 'order_status');
            $table->foreignUuid('changed_by')->nullable()->constrained('users', 'id_user');
            $table->text('note')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        // «Pide tu cotización» de la página por mayor.
        Schema::create('quote_requests', function (Blueprint $table) {
            $table->uuid('id_quote_request')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->text('name');
            $table->text('phone');
            $table->text('message')->nullable();
            $table->rawColumn('status', 'quote_status')->default('new');
            $table->foreignUuid('assigned_to')->nullable()->constrained('users', 'id_user');
            $table->timestampTz('answered_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quote_requests');
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        DB::statement('DROP SEQUENCE IF EXISTS order_number_seq');
    }
};
