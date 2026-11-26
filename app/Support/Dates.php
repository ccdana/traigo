<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/** Las horas de la API: con la hora de Bolivia, `2026-12-01T18:30:00-04:00`. */
final class Dates
{
    private const WEEKDAYS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

    private const MONTHS = [
        1 => 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic',
    ];

    private const FULL_MONTHS = [
        1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre',
        'octubre', 'noviembre', 'diciembre',
    ];

    /** «diciembre». */
    public static function monthName(DateTimeInterface|string $date): string
    {
        return self::FULL_MONTHS[Carbon::parse($date)->month];
    }

    /** «7 dic». */
    public static function short(DateTimeInterface|string $date): string
    {
        $date = Carbon::parse($date);

        return $date->day.' '.self::MONTHS[$date->month];
    }

    /** «sábado 5 dic». */
    public static function weekday(DateTimeInterface|string $date): string
    {
        $date = Carbon::parse($date);

        return self::WEEKDAYS[$date->dayOfWeek].' '.self::short($date);
    }

    /** Acepta lo que devuelve Eloquent o la consulta directa (texto). */
    public static function toApi(DateTimeInterface|string|null $date): ?string
    {
        if ($date === null) {
            return null;
        }

        return Carbon::parse($date)->timezone(config('app.timezone'))->toIso8601String();
    }
}
