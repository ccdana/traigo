<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/** Las horas de la API: con la hora de Bolivia, `2026-12-01T18:30:00-04:00`. */
final class Dates
{
    /** Acepta lo que devuelve Eloquent o la consulta directa (texto). */
    public static function toApi(DateTimeInterface|string|null $date): ?string
    {
        if ($date === null) {
            return null;
        }

        return Carbon::parse($date)->timezone(config('app.timezone'))->toIso8601String();
    }
}
