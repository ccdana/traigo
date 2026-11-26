<?php

namespace App\Services\Management;

use App\Models\User;

/** Un tipo de cosa que Dirección autoriza o devuelve. */
interface AuthorizationKind
{
    /**
     * Lo que está esperando: `{type, id, title, requested_by, requested_at,
     * amount}`.
     *
     * @return list<array<string, mixed>>
     */
    public function pending(): array;

    /**
     * El detalle antes de decidir: además de lo anterior, `pays`, `unpaid`,
     * `checks`, `changes`, `can_approve` y `blocked_reason`. Lo que ya no
     * espera: 404.
     *
     * @return array<string, mixed>
     */
    public function detail(string $id): array;

    public function approve(User $direction, string $id): void;

    /** Se devuelve a quien lo envió, con el motivo. */
    public function sendBack(User $direction, string $id, string $reason): void;
}
