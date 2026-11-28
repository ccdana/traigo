<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ConfigureAttendanceSite extends Command
{
    protected $signature = 'traigo:asistencia-sede
        {codigo} {nombre} {latitud} {longitud} {radio} {responsable : Código de Jime o Dirección}
        {--precision=30 : Precisión máxima admitida en metros}
        {--vigencia=90 : Antigüedad máxima de la lectura en segundos}
        {--borde=accuracy_within : accuracy_within o center_within}';

    protected $description = 'Configura una sede y publica una nueva versión de su rango de asistencia';

    public function handle(): int
    {
        $input = Validator::make([
            ...$this->arguments(),
            'precision' => $this->option('precision'),
            'vigencia' => $this->option('vigencia'),
            'borde' => $this->option('borde'),
        ], [
            'codigo' => ['required', 'alpha_dash:ascii', 'max:32'],
            'nombre' => ['required', 'string', 'max:100'],
            'latitud' => ['required', 'numeric', 'between:-90,90'],
            'longitud' => ['required', 'numeric', 'between:-180,180'],
            'radio' => ['required', 'numeric', 'between:1,10000'],
            'precision' => ['required', 'numeric', 'between:1,1000', 'lte:radio'],
            'vigencia' => ['required', 'integer', 'between:1,300'],
            'borde' => ['required', 'in:accuracy_within,center_within'],
        ]);
        $actor = User::query()->where('code', strtoupper($this->argument('responsable')))
            ->where('is_active', true)->whereIn('role', ['control', 'management'])->first();
        if ($input->fails() || $actor === null) {
            $this->error($input->errors()->first() ?: 'Indica el código de una cuenta activa de Jime o Dirección.');

            return self::FAILURE;
        }
        $data = $input->validated();
        $code = strtoupper($data['codigo']);

        DB::transaction(function () use ($data, $code, $actor): void {
            DB::table('attendance_sites')->insertOrIgnore(['code' => $code, 'name' => $data['nombre']]);
            $site = DB::table('attendance_sites')->where('code', $code)->lockForUpdate()->first();
            if ($site->name !== $data['nombre']) {
                DB::table('attendance_sites')->where('id_site', $site->id_site)->update(['name' => $data['nombre']]);
            }
            $policy = DB::table('attendance_policies')->insertGetId([
                'max_accuracy_m' => $data['precision'],
                'max_fix_age_seconds' => $data['vigencia'],
                'edge_rule' => $data['borde'],
                'created_by' => $actor->getKey(),
            ], 'id_policy');
            DB::table('attendance_geofences')->insert([
                'site_id' => $site->id_site,
                'policy_id' => $policy,
                'center_latitude' => $data['latitud'],
                'center_longitude' => $data['longitud'],
                'radius_m' => $data['radio'],
                'valid_from' => now()->format('Y-m-d H:i:s.uP'),
                'created_by' => $actor->getKey(),
            ]);
        });
        $this->info("Rango publicado para {$code}.");

        return self::SUCCESS;
    }
}
