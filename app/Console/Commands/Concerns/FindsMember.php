<?php

namespace App\Console\Commands\Concerns;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Str;

trait FindsMember
{
    private function findMember(string $code): ?User
    {
        $member = User::query()
            ->where('code', Str::upper(trim($code)))
            ->where('role', '<>', UserRole::Customer->value)
            ->first();

        if (! $member) {
            $this->error("No hay nadie del equipo con el código «{$code}».");
        }

        return $member;
    }
}
