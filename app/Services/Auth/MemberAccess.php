<?php

namespace App\Services\Auth;

use App\Models\TwoFactorChallenge;
use App\Models\User;
use App\Support\AuditNote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/**
 * Reponer el PIN y cerrar sesiones, desde la app (Jime, solo las cuentas del
 * equipo) o desde el servidor (`traigo:pin`). El PIN nunca se escribe en el
 * historial ni en los registros.
 */
final class MemberAccess
{
    /** PIN nuevo: desbloquea la cuenta y cierra sus sesiones. */
    public static function resetPin(User $member, #[SensitiveParameter] string $pin, ?User $by = null): void
    {
        DB::transaction(function () use ($member, $pin, $by) {
            $member->update([
                'pin_hash' => Hash::make($pin),
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ]);
            self::closeSessions($member);
            AuditNote::add($by, 'pin_reset', 'users', $member->getKey());
        });
    }

    /** Celular perdido: cierra todas sus sesiones. */
    public static function revokeSessions(User $member, ?User $by = null): void
    {
        DB::transaction(function () use ($member, $by) {
            self::closeSessions($member);
            AuditNote::add($by, 'sessions_revoked', 'users', $member->getKey());
        });
    }

    private static function closeSessions(User $member): void
    {
        $member->tokens()->delete();

        // También las sesiones del panel web.
        if (config('session.driver') === 'database') {
            DB::table(config('session.table'))->where('user_id', $member->getKey())->delete();
        }
        TwoFactorChallenge::query()
            ->where('user_id', $member->getKey())
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);
    }
}
