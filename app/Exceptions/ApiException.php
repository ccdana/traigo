<?php

namespace App\Exceptions;

use Exception;

/**
 * Un error de la API con el formato del contrato:
 * `{ "message", "code"?, "data"? }`. El mensaje va en español y listo para
 * mostrarse en la app.
 */
class ApiException extends Exception
{
    /**
     * @param  array<string, mixed>|null  $data
     * @param  array<string, string|int>  $headers
     */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly ?string $errorCode = null,
        public readonly ?array $data = null,
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function unauthorized(string $message): self
    {
        return new self(401, $message);
    }

    public static function forbidden(string $message = 'No tienes permiso para hacer esto.'): self
    {
        return new self(403, $message);
    }

    /** También para lo que no le corresponde a quien pregunta. */
    public static function notFound(string $message = 'No encontramos lo que pediste.'): self
    {
        return new self(404, $message);
    }

    /** @param  array<string, mixed>|null  $data */
    public static function conflict(string $code, string $message, ?array $data = null): self
    {
        return new self(409, $message, $code, $data);
    }

    /** @param  array<string, mixed>|null  $data */
    public static function invalid(string $message, ?string $code = null, ?array $data = null): self
    {
        return new self(422, $message, $code, $data);
    }

    public static function tooMany(string $message, int $retryAfterSeconds): self
    {
        return new self(429, $message, headers: ['Retry-After' => max(1, $retryAfterSeconds)]);
    }
}
