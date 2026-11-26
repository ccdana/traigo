<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\FindsMember;
use App\Services\Auth\MemberAccess;
use Illuminate\Console\Command;

/**
 * Pone o cambia un PIN desde el servidor: para el primer ingreso y para las
 * cuentas de Jime y Dirección (las del equipo también las cambia Jime en la
 * app). El PIN se escribe sin que se vea y no queda en el historial de la
 * consola.
 */
class SetMemberPin extends Command
{
    use FindsMember;

    protected $signature = 'traigo:pin {codigo : Código de la persona, por ejemplo JIM-01}';

    protected $description = 'Pone el PIN de alguien del equipo y cierra sus sesiones';

    public function handle(): int
    {
        $member = $this->findMember($this->argument('codigo'));

        if (! $member) {
            return self::FAILURE;
        }

        $pin = (string) $this->secret("PIN nuevo de {$member->name} (4 números)");

        if (! preg_match('/^\d{4}$/', $pin)) {
            $this->error('El PIN tiene 4 números.');

            return self::FAILURE;
        }

        if ($this->secret('Repite el PIN') !== $pin) {
            $this->error('Los dos PIN no coinciden. No se cambió nada.');

            return self::FAILURE;
        }

        MemberAccess::resetPin($member, $pin);

        $this->info("PIN de {$member->name} cambiado. Sus sesiones se cerraron.");

        return self::SUCCESS;
    }
}
