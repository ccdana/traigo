<?php

namespace App\Services\Control;

use App\Support\Dates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * El último respaldo y la última restauración probada. El respaldo lo hace el
 * servidor (hosting o un script), que avisa con `php artisan traigo:respaldo`;
 * mientras no avise, no hay datos.
 */
final class SystemStatus
{
    /** Acción del comando → clave en `settings` y su descripción. */
    public const RECORDS = [
        'hecho' => ['last_backup_at', 'Último respaldo de la base y los archivos'],
        'restauracion' => ['restore_tested_at', 'Última vez que se probó restaurar un respaldo'],
    ];

    /** @return array{last_backup_at: ?string, restore_tested_at: ?string} */
    public static function toApi(): array
    {
        $values = DB::table('settings')
            ->whereIn('key', array_column(self::RECORDS, 0))
            ->pluck('value', 'key');
        $at = function (string $key) use ($values): ?string {
            $value = json_decode($values->get($key) ?? 'null');

            return $value === null ? null : Dates::toApi($value);
        };

        return ['last_backup_at' => $at('last_backup_at'), 'restore_tested_at' => $at('restore_tested_at')];
    }

    public static function record(string $action, Carbon $at): void
    {
        [$key, $description] = self::RECORDS[$action];

        DB::table('settings')->updateOrInsert(['key' => $key], [
            'value' => json_encode($at->toIso8601String()),
            'description' => $description,
            'is_public' => false,
            'updated_at' => now(),
        ]);
    }
}
