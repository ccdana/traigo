<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Mail\TwoFactorCodeMail;
use App\Models\TwoFactorChallenge;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

/**
 * El segundo paso de Jime y Dirección: un código de 6 números que llega por
 * correo. Vence, sirve una sola vez, admite pocos intentos y en la base solo
 * se guarda su hash. Pedir otro anula el anterior.
 */
class TwoFactor
{
    public const CODE_LENGTH = 6;

    public function start(User $user): TwoFactorChallenge
    {
        $this->ensureItCanBeSent($user);
        $this->ensureNotTooMany($user);

        // Solo sirve el último código.
        TwoFactorChallenge::query()
            ->where('user_id', $user->getKey())
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = str_pad((string) random_int(0, 10 ** self::CODE_LENGTH - 1), self::CODE_LENGTH, '0', STR_PAD_LEFT);
        $minutes = config('traigo.two_factor.ttl_minutes');

        $challenge = TwoFactorChallenge::create([
            'user_id' => $user->getKey(),
            'code_hash' => Hash::make($code),
            'channel' => 'email',
            'attempts' => 0,
            'expires_at' => now()->addMinutes($minutes),
            'resend_at' => now()->addSeconds(config('traigo.two_factor.resend_after_seconds')),
        ]);

        try {
            Mail::to($user->email)->send(new TwoFactorCodeMail($user->name, $code, $minutes));
        } catch (Throwable $e) {
            report($e);
            $challenge->delete();

            throw new ApiException(503, 'No pudimos enviarte el código. Intenta de nuevo en un momento.');
        }

        return $challenge;
    }

    /** El usuario del código correcto. */
    public function verify(string $challengeId, #[SensitiveParameter] string $code): User
    {
        // Los intentos fallidos tienen que quedar guardados: el error se lanza
        // fuera de la transacción.
        $result = DB::transaction(function () use ($challengeId, $code): User|string {
            $challenge = $this->locked($challengeId);

            if (! $challenge
                || $challenge->consumed_at !== null
                || $challenge->expires_at->isPast()
                || $challenge->attempts >= config('traigo.two_factor.max_attempts')) {
                return 'expired';
            }

            if (! Hash::check($code, $challenge->code_hash)) {
                $challenge->increment('attempts');

                return 'invalid';
            }

            $user = $challenge->user;

            if (! $user->canUseApp() || ! $user->role->requiresSecondStep()) {
                return 'expired';
            }

            $challenge->update(['consumed_at' => now()]);

            return $user;
        });

        return match ($result) {
            'expired' => throw ApiException::invalid(
                'El código venció. Pide uno nuevo.',
                'two_factor_expired',
            ),
            'invalid' => throw ApiException::invalid('El código no es correcto.', 'two_factor_invalid'),
            default => $result,
        };
    }

    /** Otro código para el mismo ingreso, pasado el tiempo de espera. */
    public function resend(string $challengeId): TwoFactorChallenge
    {
        return DB::transaction(function () use ($challengeId): TwoFactorChallenge {
            $challenge = $this->locked($challengeId);
            $oldest = now()->subMinutes(config('traigo.two_factor.window_minutes'));

            if (! $challenge
                || $challenge->consumed_at !== null
                || $challenge->created_at->lt($oldest)
                || ! $challenge->user->canUseApp()) {
                throw ApiException::invalid('Vuelve a ingresar tu código y tu PIN.', 'two_factor_expired');
            }

            if ($challenge->resend_at->isFuture()) {
                throw ApiException::tooMany(
                    'Espera un momento antes de pedir otro código.',
                    (int) ceil(now()->diffInSeconds($challenge->resend_at)),
                );
            }

            return $this->start($challenge->user);
        });
    }

    private function locked(string $challengeId): ?TwoFactorChallenge
    {
        return Str::isUuid($challengeId)
            ? TwoFactorChallenge::query()->lockForUpdate()->find($challengeId)
            : null;
    }

    private function ensureItCanBeSent(User $user): void
    {
        if (blank($user->email)) {
            throw ApiException::conflict(
                'two_factor_unavailable',
                'Tu cuenta no tiene un correo para enviarte el código. Avisa a quien administra el sistema.',
            );
        }

        // En producción el código nunca se escribe en el log.
        if (app()->isProduction() && config('mail.default') === 'log') {
            report(new \RuntimeException('Falta configurar el correo (MAIL_MAILER) para enviar los códigos.'));

            throw new ApiException(503, 'El envío del código no está configurado. Avisa a quien administra el sistema.');
        }
    }

    private function ensureNotTooMany(User $user): void
    {
        $minutes = config('traigo.two_factor.window_minutes');
        $recent = TwoFactorChallenge::query()
            ->where('user_id', $user->getKey())
            ->where('created_at', '>', now()->subMinutes($minutes))
            ->orderBy('created_at')
            ->get(['created_at']);

        if ($recent->count() < config('traigo.two_factor.max_codes')) {
            return;
        }

        throw ApiException::tooMany(
            'Pediste demasiados códigos. Espera unos minutos.',
            (int) ceil(now()->diffInSeconds($recent->first()->created_at->addMinutes($minutes))),
        );
    }
}
