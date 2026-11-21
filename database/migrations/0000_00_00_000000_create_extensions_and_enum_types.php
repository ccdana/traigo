<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tipos ENUM de PostgreSQL usados por las tablas del negocio.
     *
     * @var array<string, list<string>>
     */
    private array $enumTypes = [
        'user_role' => ['customer', 'seller', 'admin'],
        'order_channel' => ['web', 'pos'],
        'order_status' => ['pending_payment', 'payment_review', 'paid', 'ready', 'completed', 'cancelled', 'expired'],
        'delivery_type' => ['pickup', 'delivery'],
        'price_type' => ['unit', 'wholesale'],
        'payment_method' => ['qr', 'card'],
        'payment_status' => ['pending', 'submitted', 'confirmed', 'rejected'],
        'commission_status' => ['pending', 'paid', 'cancelled'],
        'movement_type' => ['initial', 'purchase', 'sale_return', 'adjustment_in', 'sale_web', 'sale_pos', 'adjustment_out'],
    ];

    /**
     * Crea la extensión citext (emails sin distinguir mayúsculas) y los tipos ENUM.
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS citext');

        foreach ($this->enumTypes as $type => $values) {
            $labels = implode(', ', array_map(fn (string $value): string => "'{$value}'", $values));

            DB::statement("CREATE TYPE {$type} AS ENUM ({$labels})");
        }
    }

    /**
     * Elimina los tipos ENUM y la extensión citext.
     */
    public function down(): void
    {
        foreach (array_reverse(array_keys($this->enumTypes)) as $type) {
            DB::statement("DROP TYPE IF EXISTS {$type}");
        }

        DB::statement('DROP EXTENSION IF EXISTS citext');
    }
};
