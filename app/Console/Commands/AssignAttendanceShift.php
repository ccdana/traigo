<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class AssignAttendanceShift extends Command
{
    protected $signature = 'traigo:asistencia-turno
        {persona : Código del integrante} {sede : Código de la sede}
        {inicio : Hora de Bolivia, AAAA-MM-DD HH:MM} {fin : Hora de Bolivia, AAAA-MM-DD HH:MM}
        {responsable : Código de Jime o Dirección}';

    protected $description = 'Asigna un turno de asistencia a una persona del equipo';

    public function handle(): int
    {
        $input = Validator::make($this->arguments(), [
            'inicio' => ['required', 'date_format:Y-m-d H:i'],
            'fin' => ['required', 'date_format:Y-m-d H:i', 'after:inicio'],
        ]);
        $actor = User::query()->where('code', strtoupper($this->argument('responsable')))
            ->where('is_active', true)->whereIn('role', ['control', 'management'])->first();
        $site = DB::table('attendance_sites')->where('code', strtoupper($this->argument('sede')))->first();
        if ($input->fails() || $actor === null || $site === null) {
            $this->error($input->errors()->first() ?: 'Verifica el código de sede y el de Jime o Dirección.');

            return self::FAILURE;
        }
        $start = Carbon::createFromFormat('!Y-m-d H:i', $this->argument('inicio'), config('app.timezone'));
        $end = Carbon::createFromFormat('!Y-m-d H:i', $this->argument('fin'), config('app.timezone'));
        $assigned = DB::transaction(function () use ($start, $end, $site, $actor): bool {
            $user = User::query()->where('code', strtoupper($this->argument('persona')))->lockForUpdate()->first();
            if (! $user?->canUseApp()) {
                $this->error('La persona debe tener una cuenta activa del equipo.');

                return false;
            }
            $shifts = DB::table('attendance_assignments')->where('user_id', $user->getKey());
            if ((clone $shifts)->whereNull('cancelled_at')->where('site_id', $site->id_site)
                ->where('starts_at', $start)->where('ends_at', $end)->exists()) {
                return true;
            }
            if ($shifts->whereNull('cancelled_at')->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists()) {
                $this->error('La persona ya tiene otro turno que se cruza con ese horario.');

                return false;
            }
            DB::table('attendance_assignments')->insert([
                'user_id' => $user->getKey(),
                'site_id' => $site->id_site,
                'starts_at' => $start,
                'ends_at' => $end,
                'created_by' => $actor->getKey(),
            ]);

            return true;
        });
        if ($assigned) {
            $this->info('Turno disponible en la app.');
        }

        return $assigned ? self::SUCCESS : self::FAILURE;
    }
}
