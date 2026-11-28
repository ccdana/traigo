<?php

namespace App\Services\Store;

use App\Mail\CustomerRegistrationCodeMail;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/** El registro pertenece a la sesión que lo inició. La cuenta nace después de verificar. */
final class CustomerRegistration
{
    private const KEY = 'store.registration';

    private const CODE_MINUTES = 10;

    private const REGISTRATION_MINUTES = 30;

    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly CustomerAccounts $accounts) {}

    public function start(Session $session, #[SensitiveParameter] array $data): void
    {
        $data['password'] = Hash::make($data['password']);
        $pending = [
            'data' => $data,
            'expires_at' => now()->addMinutes(self::REGISTRATION_MINUTES)->timestamp,
            'attempts' => 0,
        ];

        $session->put(self::KEY, $this->issueCode($pending, 'email'));
    }

    public function pending(Session $session): ?array
    {
        $pending = $session->get(self::KEY);
        if ($pending !== null && $pending['expires_at'] <= now()->timestamp) {
            $session->forget(self::KEY);

            return null;
        }

        return $pending;
    }

    public function resend(Session $session, #[SensitiveParameter] array $pending): void
    {
        if ($pending['resend_at'] > now()->timestamp) {
            throw ValidationException::withMessages(['code' => 'Espera un minuto antes de pedir otro código.']);
        }

        $session->put(self::KEY, $this->issueCode($pending, 'code'));
    }

    public function complete(Session $session, #[SensitiveParameter] array $pending, #[SensitiveParameter] string $code): User
    {
        if ($pending['code_expires_at'] <= now()->timestamp) {
            throw ValidationException::withMessages(['code' => 'El código venció. Pide uno nuevo.']);
        }

        if (! Hash::check($code, $pending['code_hash'])) {
            $pending['attempts']++;
            if ($pending['attempts'] >= self::MAX_ATTEMPTS) {
                $session->forget(self::KEY);

                throw ValidationException::withMessages(['code' => 'Agotaste los intentos. Empieza el registro de nuevo.']);
            }

            $session->put(self::KEY, $pending);

            throw ValidationException::withMessages(['code' => 'El código es incorrecto. Compruébalo e inténtalo otra vez.']);
        }

        try {
            $customer = DB::transaction(fn () => $this->accounts->register($pending['data'], verified: true));
        } catch (UniqueConstraintViolationException $exception) {
            // Otro registro con el mismo correo pudo completarse mientras este estaba pendiente.
            if (! User::query()->where('email', $pending['data']['email'])->exists()) {
                throw $exception;
            }

            $session->forget(self::KEY);

            throw ValidationException::withMessages(['email' => 'Ya hay una cuenta con ese correo. Ingresa o recupera tu contraseña.']);
        }

        $session->forget(self::KEY);

        return $customer;
    }

    private function issueCode(#[SensitiveParameter] array $pending, string $field): array
    {
        if (app()->isProduction() && in_array(config('mail.default'), ['log', 'array'], true)) {
            throw ValidationException::withMessages([$field => 'El envío de códigos no está disponible en este momento.']);
        }

        $email = $pending['data']['email'];
        $limit = 'customer-registration-mail:'.hash('sha256', $email);
        if (RateLimiter::tooManyAttempts($limit, 4)) {
            throw ValidationException::withMessages([$field => 'Ya pediste varios códigos para este correo. Inténtalo más tarde.']);
        }
        RateLimiter::hit($limit, 3600);

        do {
            $code = sprintf('%06d', random_int(0, 999999));
        } while (isset($pending['code_hash']) && Hash::check($code, $pending['code_hash']));
        try {
            Mail::to($email)->send(new CustomerRegistrationCodeMail($code, self::CODE_MINUTES));
        } catch (TransportExceptionInterface) {
            throw ValidationException::withMessages([$field => 'No pudimos enviar el correo. Inténtalo de nuevo en unos minutos.']);
        }

        return [
            ...$pending,
            'code_hash' => Hash::make($code),
            'code_expires_at' => now()->addMinutes(self::CODE_MINUTES)->timestamp,
            'resend_at' => now()->addMinute()->timestamp,
        ];
    }
}
