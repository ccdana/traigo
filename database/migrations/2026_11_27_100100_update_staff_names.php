<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const NAMES = [
        'JIM-01' => ['Jime', 'Jimena'],
        'SIS-06' => ['Sistemas 1', 'Dana'],
        'SIS-07' => ['Sistemas 2', 'Brandon'],
        'DIR-08' => ['Dirección', 'Miguel'],
    ];

    public function up(): void
    {
        $this->rename(1);
    }

    public function down(): void
    {
        $this->rename(0);
    }

    private function rename(int $to): void
    {
        foreach (self::NAMES as $code => $names) {
            DB::table('users')->where('code', $code)->where('name', $names[1 - $to])
                ->update(['name' => $names[$to], 'updated_at' => now()]);
        }
    }
};
