<?php

namespace App\Support;

/** Los números de la base con el formato que espera la app. */
final class Numbers
{
    /** Un porcentaje (`"25.00"`) como tasa en texto: `"0.25"`. */
    public static function rate(string $percent): string
    {
        $rate = number_format((float) $percent / 100, 4, '.', '');

        // Al menos dos decimales: "0.25", "0.125".
        return preg_replace('/(\.\d{2}\d*?)0+$/', '$1', $rate);
    }

    /** Porcentaje entero hacia abajo: 89,9 % no llega a 90 %. */
    public static function floorPercent(string|float|int|null $value): int
    {
        return (int) floor((float) $value + 1e-6);
    }

    /** Una meta o un valor: entero si no tiene decimales. */
    public static function plain(string|float|int $value): int|float
    {
        $number = (float) $value;

        return floor($number) === $number ? (int) $number : $number;
    }
}
