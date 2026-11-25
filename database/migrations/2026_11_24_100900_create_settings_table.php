<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Configuración en pares clave/valor (QR de pago, mínimo mayorista, horas de
     * reserva, datos de contacto…). Un valor JSON null es «por definir».
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->text('key')->primary();
            $table->jsonb('value');
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(false);
            $table->foreignUuid('updated_by')->nullable()->constrained('users', 'id_user');
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
