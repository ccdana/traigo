<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un `POST` con `Idempotency-Key` se hace una sola vez: si la app reintenta
 * (por un corte de internet), recibe la misma respuesta de la primera vez.
 *
 * Solo se guardan las respuestas correctas: un error no cambió nada, así que
 * el mismo envío se puede volver a intentar.
 */
class EnsureIdempotency
{
    public const HEADER = 'Idempotency-Key';

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->headers->get(self::HEADER);

        if (! $request->isMethod('POST') || $key === null) {
            return $next($request);
        }

        if (! preg_match('/^[A-Za-z0-9\-]{8,100}$/', $key)) {
            throw ApiException::invalid('La clave del envío no es válida.');
        }

        $name = "idempotency:{$request->user()->getKey()}:{$key}";
        $lock = Cache::lock("{$name}:lock", 60);

        if (! $lock->get()) {
            throw ApiException::conflict(
                'request_in_progress',
                'Todavía estamos procesando ese envío. Espera un momento.',
            );
        }

        try {
            return $this->once($request, $next, $name);
        } finally {
            $lock->release();
        }
    }

    private function once(Request $request, Closure $next, string $name): Response
    {
        $fingerprint = $this->fingerprint($request);
        $saved = Cache::get($name);

        if ($saved !== null) {
            if ($saved['fingerprint'] !== $fingerprint) {
                throw ApiException::conflict(
                    'idempotency_conflict',
                    'Esa clave ya se usó para otro envío.',
                );
            }

            return new HttpResponse($saved['body'], $saved['status'], [
                'Content-Type' => 'application/json',
                'Idempotent-Replayed' => 'true',
            ]);
        }

        $response = $next($request);

        if ($response->isSuccessful()) {
            Cache::put($name, [
                'fingerprint' => $fingerprint,
                'status' => $response->getStatusCode(),
                'body' => (string) $response->getContent(),
            ], now()->addHours(config('traigo.idempotency_hours')));
        }

        return $response;
    }

    /** Lo que se envió: ruta, campos y contenido de los archivos. */
    private function fingerprint(Request $request): string
    {
        $files = [];
        foreach ($request->allFiles() as $field => $file) {
            $files[$field] = $file instanceof UploadedFile
                ? hash_file('sha256', $file->getRealPath())
                : null;
        }

        $input = $request->except(array_keys($files));
        ksort($input);
        ksort($files);

        return hash('sha256', json_encode([$request->path(), $input, $files]));
    }
}
