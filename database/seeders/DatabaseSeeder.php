<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Datos de arranque reales de Traigo, tomados de los Excel. No crea ventas,
     * cobros ni PIN: eso llega con el uso. Para probar el ingreso en una PC de
     * desarrollo existe LocalPinSeeder.
     */
    public function run(): void
    {
        $this->call([
            CatalogSeeder::class,
            TeamSeeder::class,
            CampaignSeeder::class,
            SettingsSeeder::class,
        ]);
    }
}
