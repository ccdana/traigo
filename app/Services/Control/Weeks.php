<?php

namespace App\Services\Control;

use App\Models\BonusWeek;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * La semana que muestra el panel. Jime cierra y paga cada semana cuando ya
 * terminó: si no pide una, se abre la terminada más antigua que sigue
 * esperando; si no queda ninguna, la semana en curso.
 */
final class Weeks
{
    /** Para «Cumplimiento» y «Cierre»: falta enviarla a Dirección. */
    public static function forClosing(?int $number): BonusWeek
    {
        return self::requested($number) ?? self::oldestEnded(
            fn (Builder $query) => $query->whereRaw("coalesce(c.status, 'open') in ('open', 'returned')"),
        );
    }

    /** Para «Bonos»: falta enviarla, autorizarla o pagarla. */
    public static function forBonus(?int $number): BonusWeek
    {
        return self::requested($number) ?? self::oldestEnded(
            fn (Builder $query) => $query->where(function (Builder $waiting) {
                $waiting->whereRaw("coalesce(c.status, 'open') in ('open', 'returned', 'sent')")
                    // Autorizada con algún pago sin marcar.
                    ->orWhereExists(fn (Builder $unpaid) => $unpaid
                        ->from('weekly_member_results as r')
                        ->whereColumn('r.week_id', 'w.id_bonus_week')
                        ->where('c.status', 'authorized')
                        ->where('r.result', 'passes')
                        ->where('r.amount_bs', '>', 0)
                        ->whereNull('r.paid_at'));
            }),
        );
    }

    /** Una que todavía no empieza no existe. */
    private static function requested(?int $number): ?BonusWeek
    {
        return $number === null ? null : BonusWeek::started($number);
    }

    /** @param  callable(Builder): mixed  $waiting */
    private static function oldestEnded(callable $waiting): BonusWeek
    {
        $query = DB::table('bonus_weeks as w')
            ->leftJoin('weekly_closings as c', 'c.week_id', '=', 'w.id_bonus_week')
            ->where('w.ends_on', '<', today()->toDateString())
            ->orderBy('w.number');
        $waiting($query);

        $id = $query->value('w.id_bonus_week');

        return $id === null ? BonusWeek::current() : BonusWeek::query()->findOrFail($id);
    }
}
