<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\User;

/** Permiso exclusivo de la web; los roles y permisos móviles no cambian. */
final class WebAdminAccess
{
    public static function allows(?User $user): bool
    {
        return $user?->is_active && $user->role === UserRole::Management;
    }
}
