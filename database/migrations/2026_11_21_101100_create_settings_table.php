<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Configuración de la tienda en pares clave/valor (QR de pago, datos de contacto...).
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->text('key')->primary();
            $table->jsonb('value');
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
