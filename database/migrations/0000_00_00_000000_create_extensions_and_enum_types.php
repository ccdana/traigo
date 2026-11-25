<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tipos ENUM de PostgreSQL. Sus valores son los mismos que viajan en la API
     * (ver el contrato de la API de la app).
     *
     * @var array<string, list<string>>
     */
    private array $enumTypes = [
        // Cuentas y aprobaciones
        'user_role' => ['customer', 'content', 'warehouse', 'wholesale', 'seller', 'systems', 'control', 'management'],
        'approval_status' => ['pending', 'approved', 'rejected'],
        // Catálogo y tienda
        'promotion_discount_type' => ['percentage', 'fixed_amount', 'fixed_price'],
        'gift_audience' => ['kids', 'home', 'school_sport'],
        'quote_status' => ['new', 'answered', 'closed'],
        // Ventas, cobros y caja
        'order_origin' => ['in_person', 'whatsapp', 'wholesale', 'web'],
        'order_status' => ['pending_payment', 'payment_review', 'paid', 'ready', 'delivered', 'cancelled', 'expired', 'returned'],
        'delivery_type' => ['pickup', 'delivery'],
        'price_type' => ['unit', 'wholesale', 'combo'],
        'payment_method' => ['qr', 'transfer', 'cash', 'card'],
        'payment_status' => ['pending', 'submitted', 'confirmed', 'rejected', 'reversed'],
        'cash_closing_status' => ['open', 'sent', 'validated', 'observed'],
        // Inventario
        'movement_type' => ['initial', 'purchase', 'sale', 'sale_return', 'adjustment_in', 'adjustment_out'],
        // Comisiones
        'commission_kind' => ['sale', 'adjustment'],
        'commission_status' => ['pending', 'earned', 'cancelled'],
        'settlement_status' => ['open', 'sent', 'authorized', 'returned', 'paid'],
        // KPI y bono semanal
        'kpi_unit' => ['count', 'percent'],
        'evidence_kind' => ['photo', 'link', 'order'],
        'evidence_status' => ['in_review', 'validated', 'observed'],
        'compliance_result' => ['pending', 'passes', 'fails'],
        'bonus_tier' => ['none', 'partial', 'complete', 'superior'],
        'week_closing_status' => ['open', 'sent', 'authorized', 'returned', 'paid'],
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
