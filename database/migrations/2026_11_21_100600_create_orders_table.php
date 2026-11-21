<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedidos web y ventas en tienda (POS). El número de pedido (N-1000, N-1001...)
     * lo genera una secuencia de PostgreSQL. Las ventas POS exigen vendedor y los
     * envíos a domicilio exigen dirección.
     */
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE order_number_seq START 1000');

        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id_order')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->text('order_number')->unique()->default(DB::raw("('N-' || nextval('order_number_seq'))"));
            $table->rawColumn('channel', 'order_channel');
            $table->rawColumn('status', 'order_status')->default('pending_payment');
            $table->foreignUuid('customer_id')->nullable()->constrained('users', 'id_user');
            $table->foreignUuid('seller_id')->nullable()->constrained('users', 'id_user');
            $table->text('customer_name');
            $table->text('customer_phone')->nullable();
            $table->rawColumn('delivery_type', 'delivery_type')->default('pickup');
            $table->text('shipping_address')->nullable();
            $table->decimal('shipping_bs', 12, 2)->default(0);
            $table->decimal('subtotal_bs', 12, 2)->default(0);
            $table->decimal('total_bs', 12, 2)->default(0);
            $table->timestampTz('expires_at')->nullable();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('ALTER SEQUENCE order_number_seq OWNED BY orders.order_number');

        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_shipping_bs_check CHECK (shipping_bs >= 0)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_subtotal_bs_check CHECK (subtotal_bs >= 0)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_total_bs_check CHECK (total_bs >= 0)');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_pos_seller_chk CHECK (channel = 'web' OR seller_id IS NOT NULL)");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_delivery_chk CHECK (delivery_type = 'pickup' OR shipping_address IS NOT NULL)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
        DB::statement('DROP SEQUENCE IF EXISTS order_number_seq');
    }
};
