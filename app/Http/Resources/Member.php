<?php

namespace App\Http\Resources;

use App\Models\User;

/** Quién usa la app, como lo pide el contrato. */
final class Member
{
    /** @return array{id: string, code: string, name: string, role: string, title: ?string} */
    public static function toArray(User $user): array
    {
        return [
            'id' => $user->getKey(),
            'code' => $user->code,
            'name' => $user->name,
            'role' => $user->role->value,
            'title' => $user->title,
        ];
    }
}
