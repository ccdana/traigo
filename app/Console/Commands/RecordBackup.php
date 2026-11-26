<?php

namespace App\Console\Commands;

use App\Services\Control\SystemStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * El sistema de respaldo del servidor lo ejecuta al terminar, y quien prueba
 * una restauración, cuando la comprueba. Sale en «Más» de la app.
 */
class RecordBackup extends Command
{
    protected $signature = 'traigo:respaldo
        {accion=hecho : «hecho» (terminó un respaldo) o «restauracion» (se probó restaurar uno)}
        {--fecha= : Cuándo fue, «2026-12-06 03:00» en hora de Bolivia (si no, ahora)}';

    protected $description = 'Anota un respaldo o una restauración probada';

    public function handle(): int
    {
        $action = $this->argument('accion');

        if (! isset(SystemStatus::RECORDS[$action])) {
            $this->error('La acción es «hecho» o «restauracion».');

            return self::FAILURE;
        }

        try {
            $at = $this->option('fecha') === null
                ? now()
                : Carbon::createFromFormat('Y-m-d H:i', $this->option('fecha'), config('app.timezone'));
        } catch (Throwable) {
            $at = null;
        }

        if (! $at) {
            $this->error('La fecha se escribe como «2026-12-06 03:00».');

            return self::FAILURE;
        }

        if ($at->gt(now())) {
            $this->error('La fecha no puede ser futura.');

            return self::FAILURE;
        }

        SystemStatus::record($action, $at);
        $this->info($action === 'hecho' ? 'Respaldo anotado.' : 'Restauración probada anotada.');

        return self::SUCCESS;
    }
}
