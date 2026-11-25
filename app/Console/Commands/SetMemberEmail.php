<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\FindsMember;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** A ese correo llega el código del segundo paso de Jime y Dirección. */
class SetMemberEmail extends Command
{
    use FindsMember;

    protected $signature = 'traigo:correo {codigo : Código de la persona} {correo : Su correo}';

    protected $description = 'Pone el correo de alguien del equipo (ahí llega su código de acceso)';

    public function handle(): int
    {
        $member = $this->findMember($this->argument('codigo'));

        if (! $member) {
            return self::FAILURE;
        }

        $email = trim($this->argument('correo'));
        $check = Validator::make(['correo' => $email], [
            'correo' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($member->getKey(), 'id_user'),
            ],
        ]);

        if ($check->fails()) {
            $this->error($check->errors()->first());

            return self::FAILURE;
        }

        $member->update(['email' => $email]);
        $this->info("Correo de {$member->name} guardado.");

        return self::SUCCESS;
    }
}
