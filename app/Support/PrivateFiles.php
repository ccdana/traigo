<?php

namespace App\Support;

use App\Exceptions\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Guarda las fotos que sube el equipo (ver {@see FileLinks}). */
final class PrivateFiles
{
    /**
     * @param  string  $name  lo que se ve en la app; se le agrega una parte al
     *                        azar para que no se pueda adivinar
     * @return string la ruta guardada
     */
    public static function put(UploadedFile $file, string $folder, string $name): string
    {
        $extension = $file->guessExtension() === 'jpeg' ? 'jpg' : $file->guessExtension();
        $filename = "{$name}-".Str::lower(Str::random(12)).".{$extension}";

        $path = Storage::disk(FileLinks::DISK)->putFileAs($folder, $file, $filename);

        if ($path === false) {
            throw new ApiException(500, 'No pudimos guardar el archivo. Intenta de nuevo.');
        }

        return $path;
    }

    public static function forget(?string $path): void
    {
        if ($path !== null) {
            Storage::disk(FileLinks::DISK)->delete($path);
        }
    }
}
