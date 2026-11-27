<?php

namespace App\Services\Media;

use Illuminate\Http\UploadedFile;

/**
 * Dónde se guardan las imágenes públicas (fotos de productos y el QR de pago).
 * Cambiar de proveedor es escribir otra implementación; el resto no se toca.
 */
interface ImageStorage
{
    /** @return array{public_id: string, url: string} `url` es una dirección completa */
    public function store(UploadedFile $file, string $folder): array;

    public function delete(string $publicId): void;

    /** Si esa imagen la guardó este proveedor. */
    public function owns(string $publicId): bool;
}
