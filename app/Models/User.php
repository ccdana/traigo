<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Mail\CustomerPasswordResetMail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'role', 'code', 'name', 'lastname', 'title', 'email', 'phone', 'password', 'pin_hash',
    'failed_login_attempts', 'locked_until', 'last_login_at', 'is_active',
])]
#[Hidden(['password', 'pin_hash', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    protected $primaryKey = 'id_user';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'email_verified_at' => 'datetime',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'failed_login_attempts' => 'integer',
            'password' => 'hashed',
        ];
    }

    /** Un cliente de la tienda con la cuenta activa. */
    public function isActiveCustomer(): bool
    {
        return $this->is_active && $this->role === UserRole::Customer;
    }

    /** Solo los clientes tienen contraseña: el correo va en español y a la tienda. */
    public function sendPasswordResetNotification($token): void
    {
        Mail::to($this->email)->send(new CustomerPasswordResetMail(
            $this->name,
            route('store.password.reset', ['token' => $token, 'email' => $this->email]),
            (int) config('auth.passwords.users.expire'),
        ));
    }

    /** Del equipo y con la cuenta activa: lo único que puede usar la API. */
    public function canUseApp(): bool
    {
        return $this->is_active && $this->role->isStaff();
    }
}
