<?php

namespace App\Services\Store;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Las cuentas de los clientes de la tienda. El rol lo pone siempre el
 * servidor: nada de lo que llegue de un formulario puede crear o tocar una
 * cuenta del equipo.
 */
class CustomerAccounts
{
    /**
     * Lo que identifica a una cuenta de cliente al ingresar o recuperar la
     * contraseña. El equipo no entra por aquí.
     *
     * @return array{email: string, role: string, is_active: bool}
     */
    public static function credentials(string $email): array
    {
        return [
            'email' => mb_strtolower(trim($email)),
            'role' => UserRole::Customer->value,
            'is_active' => true,
        ];
    }

    /**
     * Deja lista la sesión de quien acaba de ingresar: cambia su id, conserva
     * la bolsa y descarta la huella de contraseña de una cuenta anterior (si
     * quedara, la nueva sesión se cerraría sola).
     */
    public function startSession(Session $session): void
    {
        $session->forget('password_hash_customer');
        $session->regenerate();
    }

    /** @param  array{name: string, lastname: string, phone: string, email: string, password: string}  $data  ya validado */
    public function register(#[SensitiveParameter] array $data): User
    {
        return User::create([
            ...$this->contact($data),
            'role' => UserRole::Customer,
            // El modelo la guarda cifrada.
            'password' => $data['password'],
            'is_active' => true,
        ]);
    }

    /** @param  array{name: string, lastname: string, phone: string, email: string}  $data  ya validado */
    public function updateData(User $customer, array $data): void
    {
        $customer->update($this->contact($data));
    }

    /** Cambia la contraseña y deja sin efecto «mantener la sesión iniciada». */
    public function changePassword(User $customer, #[SensitiveParameter] string $password): void
    {
        $customer->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
    }

    /** Cierra todas sus sesiones, por ejemplo al restablecer la contraseña. */
    public function closeSessions(User $customer): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config('session.table'))->where('user_id', $customer->getKey())->delete();
        }
    }

    /** @return array{name: string, lastname: string, phone: string, email: string} */
    private function contact(array $data): array
    {
        return [
            'name' => Str::squish($data['name']),
            'lastname' => Str::squish($data['lastname']),
            'phone' => $data['phone'],
            'email' => $data['email'],
        ];
    }
}
