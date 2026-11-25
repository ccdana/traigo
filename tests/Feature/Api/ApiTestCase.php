<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Database\Seeders\LocalPinSeeder;
use Database\Seeders\TeamSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** El equipo real (con PIN 1234) y atajos para llamar a la API. */
abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Los datos del Excel que necesita el equipo; nada más.
        $this->seed([TeamSeeder::class, LocalPinSeeder::class]);
    }

    protected function member(string $code): User
    {
        return User::query()->where('code', $code)->firstOrFail();
    }

    protected function login(string $code, string $pin = '1234'): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', [
            'code' => $code,
            'pin' => $pin,
            'device_name' => 'Pruebas',
        ]);
    }

    protected function tokenFor(string $code): string
    {
        return $this->member($code)->createToken('Pruebas')->plainTextToken;
    }

    /**
     * Entre peticiones, el guard recuerda quién era: en un servidor de verdad
     * cada petición empieza de cero.
     */
    protected function as(?string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $token === null
            ? $this->withoutHeader('Authorization')
            : $this->withHeader('Authorization', "Bearer {$token}");
    }
}
