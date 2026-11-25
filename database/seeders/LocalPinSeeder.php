<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class LocalPinSeeder extends Seeder
{
    /**
     * Solo para probar en una PC de desarrollo: pone el PIN 1234 a todo el
     * equipo, igual que el modo demo de la app. Nunca corre en producción.
     *
     *   php artisan db:seed --class=LocalPinSeeder
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('LocalPinSeeder solo corre con APP_ENV=local o testing.');
        }

        DB::table('users')->where('role', '<>', 'customer')->update(['pin_hash' => Hash::make('1234')]);
    }
}
