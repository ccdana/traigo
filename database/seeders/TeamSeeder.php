<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TeamSeeder extends Seeder
{
    /**
     * El equipo, Jime y Dirección, sin PIN (cada uno recibe el suyo después).
     * Los códigos son una propuesta; se pueden cambiar antes de entregar los PIN.
     *
     * @var list<array{code: string, name: string, role: string, title: string}>
     */
    public const MEMBERS = [
        ['code' => 'JIM-01', 'name' => 'Jimena', 'role' => 'control', 'title' => 'Control y validación'],
        ['code' => 'AIL-02', 'name' => 'Ailén', 'role' => 'content', 'title' => 'Contenido y lives'],
        ['code' => 'ISA-03', 'name' => 'Isaí', 'role' => 'warehouse', 'title' => 'Almacén y caja'],
        ['code' => 'ALI-04', 'name' => 'Alison', 'role' => 'seller', 'title' => 'Ventas por comisión'],
        ['code' => 'KIM-05', 'name' => 'Kim', 'role' => 'wholesale', 'title' => 'Ventas mayoristas'],
        ['code' => 'SIS-06', 'name' => 'Dana', 'role' => 'systems', 'title' => 'Sistemas'],
        ['code' => 'SIS-07', 'name' => 'Brandon', 'role' => 'systems', 'title' => 'Sistemas'],
        ['code' => 'DIR-08', 'name' => 'Miguel', 'role' => 'management', 'title' => 'Dirección'],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            $campaign = require __DIR__.'/data/campaign.php';
            $shares = array_column($campaign['members'], null, 'member');

            foreach (self::MEMBERS as $order => $member) {
                $userId = DB::table('users')->insertGetId($member, 'id_user');

                if (isset($shares[$member['name']])) {
                    DB::table('team_profiles')->insert([
                        'user_id' => $userId,
                        'bonus_share_pct' => $shares[$member['name']]['bonus_share_pct'],
                        'min_kpi_pct' => $shares[$member['name']]['min_kpi_pct'],
                        'sort_order' => $order,
                    ]);
                }
            }
        });
    }
}
