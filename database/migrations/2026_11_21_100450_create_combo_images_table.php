<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Imágenes de combos guardadas en Cloudinary; position 0 es la principal.
     */
    public function up(): void
    {
        Schema::create('combo_images', function (Blueprint $table) {
            $table->uuid('id_combo_image')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('combo_id')->constrained('combos', 'id_combo')->cascadeOnDelete();
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
    }
};
