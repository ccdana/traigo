<?php

namespace App\Exceptions;

use Exception;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Un error de la API con el formato del contrato:
 * `{ "message", "code"?, "data"? }`. El mensaje va en español y listo para
 * mostrarse en la app. Fuera de la API (páginas web) se comporta como un error
 * HTTP con el mismo código: un 403 no se vuelve un 500.
 */
class ApiException extends Exception implements HttpExceptionInterface
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

    public function getStatusCode(): int
    {
        return $this->status;
    }

    /** @return array<string, string|int> */
    public function getHeaders(): array
    {
        return $this->headers;
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
