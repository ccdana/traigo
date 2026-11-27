<?php

namespace App\Services\Store;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Conserva la ubicación sin cambiar el contrato de pedidos de la API móvil. */
final class DeliveryLocations
{
    public static function mapUrl(string|float $latitude, string|float $longitude): string
    {
        return 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($latitude.','.$longitude);
    }

    public static function addressForApp(array $delivery): ?string
    {
        if ($delivery['delivery_type'] !== 'delivery') {
            return null;
        }
        return implode("\n", array_filter([
            Str::squish((string) $delivery['address']),
            filled($delivery['reference'] ?? null) ? 'Referencia: '.Str::squish($delivery['reference']) : null,
            isset($delivery['latitude'], $delivery['longitude'])
                ? self::mapUrl($delivery['latitude'], $delivery['longitude']) : null,
        ]));
    }

    public function save(Order $order, array $delivery): void
    {
        if ($delivery['delivery_type'] !== 'delivery' || ! isset($delivery['latitude'], $delivery['longitude'])) {
            return;
        }
        DB::table('order_delivery_locations')->insert([
            'order_id' => $order->getKey(), 'latitude' => $delivery['latitude'], 'longitude' => $delivery['longitude'],
            'address' => Str::squish($delivery['address']),
            'reference' => filled($delivery['reference'] ?? null) ? Str::squish($delivery['reference']) : null,
        ]);
    }

    public function of(string $orderId): ?object
    {
        return DB::table('order_delivery_locations')->where('order_id', $orderId)->first();
    }
}
