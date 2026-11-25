<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SettingsSeeder extends Seeder
{
    /**
     * Lo que ya está decidido y lo que falta (null = por definir).
     * is_public: la tienda web puede leerlo sin sesión.
     */
    public function run(): void
    {
        $settings = [
            ['wholesale_min_order_bs', 1000, 'Pedido mayorista mínimo (Bs), sumando los códigos que llegan a su mínimo', true],
            ['order_reservation_hours', 2, 'Horas que se reserva un pedido web mientras se paga', true],
            ['payment_qr_url', null, 'Imagen del QR de pago de Traigo', true],
            ['store_whatsapp', null, 'WhatsApp de la tienda', true],
            ['store_tiktok', '@i_traigo', 'Usuario de TikTok', true],
            ['pickup_address', null, 'Dirección del punto de recojo', true],
            ['delivery_fee_note', null, 'Costo del delivery en Cochabamba', true],
            ['christmas_order_deadline', null, 'Último día para pedir y recibir antes de Nochebuena', true],
            ['same_day_cutoff_time', null, 'Hora límite para entrega el mismo día', true],
            ['commission_pay_note', null, 'Cuándo se pagan las comisiones', false],
        ];

        foreach ($settings as [$key, $value, $description, $public]) {
            DB::table('settings')->insert([
                'key' => $key,
                'value' => json_encode($value),
                'description' => $description,
                'is_public' => $public,
            ]);
        }
    }
}
