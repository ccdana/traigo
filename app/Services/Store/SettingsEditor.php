<?php

namespace App\Services\Store;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Guarda los datos de la tienda desde el panel. Solo escribe lo que cambió, así
 * el historial (que lo anota la base) dice la verdad.
 */
class SettingsEditor
{
    /** Los que se editan como texto; el QR de pago va aparte. */
    public const TEXT_KEYS = [
        'store_whatsapp', 'store_tiktok', 'pickup_address', 'delivery_fee_note',
        'christmas_order_deadline', 'order_reservation_hours',
    ];

    /** @return array<string, mixed> los valores actuales */
    public function all(): array
    {
        return DB::table('settings')
            ->whereIn('key', [...self::TEXT_KEYS, 'payment_qr_url'])
            ->pluck('value', 'key')
            ->map(fn (string $value) => json_decode($value, true))
            ->all();
    }

    /** @param  array<string, string|int|null>  $values  nulo deja el dato «por definir» */
    public function save(User $by, array $values): void
    {
        DB::transaction(function () use ($by, $values) {
            $current = $this->all();

            foreach ($values as $key => $value) {
                if (array_key_exists($key, $current) && $current[$key] === $value) {
                    continue;
                }

                $row = ['value' => json_encode($value), 'updated_by' => $by->getKey(), 'updated_at' => now()];

                if (DB::table('settings')->where('key', $key)->update($row) === 0) {
                    DB::table('settings')->insert([...$row, 'key' => $key, 'is_public' => true]);
                }
            }
        });
    }
}
