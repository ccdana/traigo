<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use SensitiveParameter;

/** El primer paso del ingreso: código y PIN, con bloqueo por intentos. */
class PinLogin
{
    private const WRONG = 'Código o PIN incorrectos.';

    public function check(string $code, #[SensitiveParameter] string $pin): User
    {
        // El contador de fallos tiene que quedar guardado: el error se lanza
        // fuera de la transacción.
        $result = DB::transaction(function () use ($code, $pin): User|Carbon|null {
            $user = User::query()
                ->where('code', Str::upper(trim($code)))
                ->where('role', '<>', UserRole::Customer->value)
                ->lockForUpdate()
                ->first();

            if ($user?->locked_until?->isFuture()) {
                return $user->locked_until;
            }

            // Se comprueba un hash exista o no la cuenta, para que la demora
            // no revele qué códigos existen.
            $matches = Hash::check($pin, $user?->pin_hash ?? $this->decoyHash());

            if (! $user || ! $user->is_active || $user->pin_hash === null || ! $matches) {
                return $user?->is_active ? $this->registerFailure($user) : null;
            }

            $user->update(['failed_login_attempts' => 0, 'locked_until' => null]);

            return $user;
        });

        if ($result instanceof Carbon) {
            throw $this->locked($result);
        }

        return $result ?? throw ApiException::unauthorized(self::WRONG);
    }

    /** Devuelve hasta cuándo queda bloqueada, si este fallo la bloqueó. */
    private function registerFailure(User $user): ?Carbon
    {
        $failures = $user->failed_login_attempts + 1;

        if ($failures < config('traigo.login.lock_after_failures')) {
            $user->update(['failed_login_attempts' => $failures]);

            return null;
        }

        $until = now()->addMinutes(config('traigo.login.lock_minutes'));
        $user->update(['failed_login_attempts' => 0, 'locked_until' => $until]);

        return $until;
    }

    private function locked(Carbon $until): ApiException
    {
        $seconds = (int) ceil(now()->diffInSeconds($until));
        $minutes = (int) ceil($seconds / 60);

        return ApiException::tooMany(
            'Demasiados intentos. Espera '.($minutes === 1 ? '1 minuto' : "{$minutes} minutos").'.',
            $seconds,
        );
    }

    private function decoyHash(): string
    {
        return Cache::rememberForever('auth:decoy-hash', fn () => Hash::make(Str::random(32)));
    }
}
