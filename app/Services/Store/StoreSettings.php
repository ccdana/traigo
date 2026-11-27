<?php

namespace App\Services\Store;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Los datos de la tienda que se cambian sin tocar código (tabla `settings`).
 * Solo lee los marcados como públicos. Nulo significa «por definir» y la
 * página simplemente no lo muestra.
 */
class StoreSettings
{
    /** @var array<string, mixed>|null */
    private ?array $values = null;

    public function get(string $key): mixed
    {
        $this->values ??= DB::table('settings')
            ->where('is_public', true)
            ->pluck('value', 'key')
            ->map(fn (string $value) => json_decode($value, true))
            ->all();

        return $this->values[$key] ?? null;
    }

    /** Horas que un pedido web aparta stock mientras se paga. */
    public function reservationHours(): int
    {
        $hours = (int) $this->get('order_reservation_hours');

        return $hours > 0 ? $hours : (int) config('traigo.web.default_reservation_hours');
    }

    public function paymentQrUrl(): ?string
    {
        $url = $this->get('payment_qr_url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    /** Sin un QR cargado la tienda no recibe pedidos: no habría cómo pagarlos. */
    public function onlinePaymentEnabled(): bool
    {
        return $this->paymentQrUrl() !== null;
    }

    public function whatsappUrl(?string $message = null): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $this->get('store_whatsapp'));

        if ($digits === '') {
            return null;
        }

        return "https://wa.me/{$digits}".($message === null ? '' : '?text='.rawurlencode($message));
    }

    /**
     * Hasta cuándo corre la cuenta regresiva: el último día para pedir si está
     * definido y, si no, Nochebuena.
     *
     * @return array{at: Carbon, label: string}
     */
    public function countdown(): array
    {
        $deadline = $this->get('christmas_order_deadline');

        if (is_string($deadline)) {
            try {
                return ['at' => Carbon::parse($deadline)->endOfDay(), 'label' => 'Para pedir a tiempo'];
            } catch (Throwable) {
                // Un texto que no es fecha no rompe la portada.
            }
        }

        return ['at' => Carbon::create(now()->year, 12, 24)->startOfDay(), 'label' => 'Para Nochebuena'];
    }
}
