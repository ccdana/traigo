<?php

namespace App\Services\Media;

use App\Exceptions\ApiException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Cloudinary por su API HTTP con envíos firmados, sin librerías extra. Se
 * activa al definir `CLOUDINARY_URL=cloudinary://clave:secreto@nube`. El
 * secreto solo vive en el servidor.
 */
class CloudinaryImageStorage implements ImageStorage
{
    private const ROOT = 'traigo';

    private readonly string $cloud;

    private readonly string $key;

    private readonly string $secret;

    public function __construct(#[SensitiveParameter] string $url)
    {
        $parts = parse_url($url);

        if (($parts['scheme'] ?? null) !== 'cloudinary' || ! isset($parts['host'], $parts['user'], $parts['pass'])) {
            throw new InvalidArgumentException('CLOUDINARY_URL debe tener la forma cloudinary://clave:secreto@nube.');
        }

        $this->cloud = $parts['host'];
        $this->key = $parts['user'];
        $this->secret = $parts['pass'];
    }

    public function store(UploadedFile $file, string $folder): array
    {
        $response = Http::timeout(30)
            ->attach('file', file_get_contents($file->getRealPath()), $file->hashName())
            ->post($this->endpoint('upload'), $this->signed(['folder' => self::ROOT."/{$folder}"]));

        $this->ensureOk($response, 'No pudimos subir la imagen a Cloudinary. Intenta de nuevo.');

        return ['public_id' => $response->json('public_id'), 'url' => $response->json('secure_url')];
    }

    public function delete(string $publicId): void
    {
        $response = Http::timeout(30)
            ->asForm()
            ->post($this->endpoint('destroy'), $this->signed(['public_id' => $publicId]));

        $this->ensureOk($response, 'No pudimos borrar la imagen en Cloudinary.');
    }

    public function owns(string $publicId): bool
    {
        return str_starts_with($publicId, self::ROOT.'/');
    }

    private function endpoint(string $action): string
    {
        return "https://api.cloudinary.com/v1_1/{$this->cloud}/image/{$action}";
    }

    /**
     * La firma de Cloudinary: los parámetros en orden alfabético, seguidos del
     * secreto, en SHA-1.
     *
     * @param  array<string, string>  $params
     * @return array<string, string|int>
     */
    private function signed(array $params): array
    {
        $params['timestamp'] = now()->timestamp;
        ksort($params);

        $payload = collect($params)->map(fn ($value, string $key) => "{$key}={$value}")->implode('&');

        return [...$params, 'api_key' => $this->key, 'signature' => sha1($payload.$this->secret)];
    }

    private function ensureOk(Response $response, string $message): void
    {
        if ($response->failed()) {
            report(new \RuntimeException("Cloudinary respondió {$response->status()}: ".$response->json('error.message')));

            throw new ApiException(502, $message);
        }
    }
}
