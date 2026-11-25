<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Usuarios y las tablas de sesión de Laravel.
     *
     * - El equipo (integrantes, Jime y Dirección) entra con código + PIN; Jime y
     *   Dirección además pasan un segundo paso.
     * - Los clientes de la tienda web entran con email o teléfono y contraseña.
     *   Comprar no exige cuenta.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id_user')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->rawColumn('role', 'user_role')->default('customer');
            $table->text('code')->nullable()->unique();
            $table->text('name');
            $table->text('lastname')->nullable();
            $table->text('title')->nullable();
            $table->rawColumn('email', 'citext')->nullable()->unique();
            $table->text('phone')->nullable();
            $table->timestampTz('email_verified_at')->nullable();
            $table->text('password')->nullable();
            $table->text('pin_hash')->nullable();
            $table->smallInteger('failed_login_attempts')->default(0);
            $table->timestampTz('locked_until')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        // El equipo tiene código; un cliente nunca.
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_code_by_role_chk CHECK ((role = 'customer') = (code IS NULL))");
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_code_upper_chk CHECK (code IS NULL OR code = upper(code))');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_customer_contact_chk CHECK (role <> 'customer' OR email IS NOT NULL OR phone IS NOT NULL)");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_pin_only_team_chk CHECK (role <> 'customer' OR pin_hash IS NULL)");
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_failed_attempts_chk CHECK (failed_login_attempts >= 0)');

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
