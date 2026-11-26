<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Lo que la base no registra sola (PIN nuevo, sesiones cerradas, promociones).
 * El historial solo admite agregar. Nunca va un PIN ni un dato secreto.
 */
final class AuditNote
{
    /** @param  array<string, mixed>  $values */
    public static function add(?User $by, string $action, string $entity, string $id, array $values = []): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => $by?->getKey(),
            'action' => $action,
            'entity_type' => $entity,
            'entity_id' => $id,
            'new_values' => $values === [] ? null : json_encode($values, JSON_UNESCAPED_UNICODE),
        ]);
    }
}
