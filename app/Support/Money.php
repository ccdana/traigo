<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Dinero en centavos enteros, para no arrastrar errores de redondeo. La base
 * lo guarda como `numeric(12,2)` y la API lo envía como texto: `"412.50"`.
 */
final class Money
{
    public const PATTERN = '/^(-?)(\d{1,10})(?:\.(\d{1,2}))?$/';

    public static function toCents(string|int $amount): int
    {
        if (! preg_match(self::PATTERN, (string) $amount, $match)) {
            throw new InvalidArgumentException("Monto inválido: «{$amount}».");
        }

        $cents = (int) $match[2] * 100 + (int) str_pad($match[3] ?? '', 2, '0');

        return $match[1] === '-' ? -$cents : $cents;
    }

    public static function format(int $cents): string
    {
        return ($cents < 0 ? '-' : '').intdiv(abs($cents), 100).'.'.str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Lo que devuelve la base (o nulo), con el formato de la API. */
    public static function fromDatabase(string|int|null $amount): ?string
    {
        return $amount === null ? null : self::format(self::toCents($amount));
    }
}
