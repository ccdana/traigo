<?php

namespace App\Services\Team;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Una semana con su cierre en Dirección (enviado), autorizado o pagado ya no
 * cambia: Dirección autoriza justo lo que Jime envió. Si hay que corregir,
 * Dirección lo devuelve.
 */
final class WeekLock
{
    public const FROZEN = ['sent', 'authorized', 'paid'];

    /**
     * El estado del cierre de la semana. Con [lock] (dentro de una
     * transacción), la semana no se puede enviar hasta que termine el cambio.
     */
    public static function status(string $weekId, bool $lock = false): string
    {
        $query = DB::table('weekly_closings')->where('week_id', $weekId);

        if ($lock) {
            $query->sharedLock();
        }

        return $query->value('status') ?? 'open';
    }

    public static function isFrozen(string $weekId, bool $lock = false): bool
    {
        return in_array(self::status($weekId, $lock), self::FROZEN, true);
    }

    /** Antes de que Jime cambie algo de la semana, dentro de su transacción. */
    public static function ensureOpen(string $weekId): void
    {
        match (self::status($weekId, lock: true)) {
            'sent' => throw ApiException::conflict(
                'week_closed',
                'El cierre de esa semana está con Dirección. Para cambiar algo, tiene que devolverlo.',
            ),
            'authorized', 'paid' => throw ApiException::conflict(
                'week_closed',
                'La semana ya está autorizada: no se puede cambiar.',
            ),
            default => null,
        };
    }

    /** La semana de campaña de ese día (si hay). */
    public static function weekOfDay(string $date): ?string
    {
        return DB::table('bonus_weeks')
            ->where('starts_on', '<=', $date)
            ->where('ends_on', '>=', $date)
            ->value('id_bonus_week');
    }
}
