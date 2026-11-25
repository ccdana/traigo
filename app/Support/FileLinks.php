<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Los archivos privados (comprobantes) se guardan en el servidor, en
 * `storage/app/private`, y salen con un enlace firmado que vence. Esa carpeta
 * tiene que entrar en el respaldo.
 */
final class FileLinks
{
    public const DISK = 'local';

    public static function temporary(?string $pathOrUrl): ?string
    {
        if ($pathOrUrl === null) {
            return null;
        }

        // Por si algún archivo viene de un servicio externo.
        if (str_starts_with($pathOrUrl, 'http')) {
            return $pathOrUrl;
        }

        return Storage::disk(self::DISK)->temporaryUrl(
            $pathOrUrl,
            now()->addMinutes(config('traigo.file_link_minutes')),
        );
    }
}
