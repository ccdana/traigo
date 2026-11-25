<?php

namespace App\Support;

use App\Exceptions\ApiException;
use App\Http\Middleware\EnsureIdempotency;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * La clave del envío (`Idempotency-Key`): la app la repite al reintentar, así
 * nada se registra dos veces.
 */
final class ClientKey
{
    public static function require(Request $request): string
    {
        $key = (string) $request->headers->get(EnsureIdempotency::HEADER);

        if (! Str::isUuid($key)) {
            throw ApiException::invalid('Falta la clave del envío.');
        }

        return Str::lower($key);
    }
}
