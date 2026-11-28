<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_delivery_locations', function (Blueprint $table) {
            $table->foreignUuid('order_id')->primary()->constrained('orders', 'id_order')->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('address', 300);
            $table->string('reference', 300)->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::statement('ALTER TABLE order_delivery_locations ADD CONSTRAINT delivery_coordinates_check CHECK (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180)');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_delivery_locations');
    }
};
