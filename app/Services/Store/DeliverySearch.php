<?php

namespace App\Services\Store;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Búsquedas explícitas, cacheadas y limitadas para el proveedor Photon. */
final class DeliverySearch
{
    public function search(string $query): array
    {
        $query = Str::squish($query);
        $url = config('delivery-map.search_url');
        $key = 'delivery-search:'.hash('sha256', $url.'|'.mb_strtolower($query));
        if (($cached = Cache::get($key)) !== null) {
            return $cached;
        }
        // Un único envío a la vez, y como máximo uno cada dos segundos en toda la web.
        $lock = Cache::lock('delivery-search:provider', 15);
        if (! $lock->get()) {
            throw new HttpException(429, 'Espera unos segundos antes de buscar otra vez.');
        }
        try {
            if (! Cache::add('delivery-search:cooldown', true, 2)) {
                throw new HttpException(429, 'Espera unos segundos antes de buscar otra vez.');
            }
            $response = Http::acceptJson()->withUserAgent('TraigoDelivery/1.0 (+'.config('app.url').')')
                ->connectTimeout(3)->timeout(8)->get($url, [
                    'q' => $query, 'limit' => 5,
                    'lat' => config('delivery-map.latitude'), 'lon' => config('delivery-map.longitude'),
                    'bbox' => '-69.7,-22.9,-57.4,-9.6',
                ]);
            if (! $response->successful() || ! is_array($response->json('features'))) {
                throw new HttpException(503, 'La búsqueda no está disponible. Puedes marcar el punto directamente en el mapa.');
            }
            $results = collect($response->json('features'))->map(function ($feature): ?array {
                if (! is_array($feature)) {
                    return null;
                }
                $coordinates = $feature['geometry']['coordinates'] ?? [];
                $properties = $feature['properties'] ?? [];
                if (! is_array($coordinates) || ! is_array($properties)
                    || ! isset($coordinates[0], $coordinates[1]) || ! is_numeric($coordinates[0]) || ! is_numeric($coordinates[1])
                    || abs((float) $coordinates[0]) > 180 || abs((float) $coordinates[1]) > 90) {
                    return null;
                }
                $label = collect(['name', 'street', 'housenumber', 'district', 'city', 'state', 'country'])
                    ->map(fn ($key) => $properties[$key] ?? null)->filter(fn ($value) => is_string($value) && $value !== '')
                    ->unique()->implode(', ');
                return ['label' => $label ?: 'Punto encontrado', 'latitude' => (float) $coordinates[1], 'longitude' => (float) $coordinates[0]];
            })->filter()->values()->all();
            Cache::put($key, $results, now()->addDay());
            return $results;
        } catch (ConnectionException $e) {
            throw new HttpException(503, 'La búsqueda no responde. Puedes marcar el punto directamente en el mapa.', $e);
        } finally {
            $lock->release();
        }
    }
}
