<?php

namespace App\Services\Media;

use App\Exceptions\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * En el propio servidor, en `storage/app/public` (necesita `php artisan
 * storage:link`). La dirección usa `APP_URL`: la app móvil la recibe completa.
 */
class LocalImageStorage implements ImageStorage
{
    private const PREFIX = 'local:';

    public function store(UploadedFile $file, string $folder): array
    {
        $extension = $file->guessExtension() === 'jpeg' ? 'jpg' : $file->guessExtension();
        $path = Storage::disk('public')->putFileAs($folder, $file, Str::lower(Str::random(24)).".{$extension}");

        if ($path === false) {
            throw new ApiException(500, 'No pudimos guardar la imagen. Intenta de nuevo.');
        }

        return ['public_id' => self::PREFIX.$path, 'url' => Storage::disk('public')->url($path)];
    }

    public function delete(string $publicId): void
    {
        Storage::disk('public')->delete(Str::after($publicId, self::PREFIX));
    }

    public function owns(string $publicId): bool
    {
        return str_starts_with($publicId, self::PREFIX);
    }
}
