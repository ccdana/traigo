<?php

namespace App\Support;

use App\Exceptions\ApiException;

/** Los enlaces de evidencia y entregas (igual que `normalizeLink` en la app). */
final class WebLinks
{
    /** Completa «https://» si falta; si no es un enlace web, error. */
    public static function require(string $raw): string
    {
        $text = trim($raw);

        if ($text !== '' && ! preg_match('/\s/', $text)) {
            if (! str_contains($text, '://')) {
                $text = "https://{$text}";
            }

            $scheme = parse_url($text, PHP_URL_SCHEME);
            $host = parse_url($text, PHP_URL_HOST);

            if (in_array($scheme, ['http', 'https'], true)
                && is_string($host)
                && str_contains($host, '.')
                && ! str_starts_with($host, '.')
                && ! str_ends_with($host, '.')) {
                return $text;
            }
        }

        throw ApiException::invalid('El enlace no es válido.');
    }
}
