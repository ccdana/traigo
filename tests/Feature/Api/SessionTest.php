<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;

class SessionTest extends ApiTestCase
{
    #[Test]
    public function sin_sesion_la_api_responde_401_aunque_no_se_pida_json(): void
    {
        $this->get('/api/v1/me')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Tu sesión terminó. Ingresa otra vez.']);

        $this->as('1|inventado')->getJson('/api/v1/me')->assertUnauthorized();
    }

    #[Test]
    public function salir_cierra_solo_la_sesion_de_ese_celular(): void
    {
        $phone = $this->tokenFor('ALI-04');
        $tablet = $this->tokenFor('ALI-04');

        $this->as($phone)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->as($phone)->getJson('/api/v1/me')->assertUnauthorized();
        $this->as($tablet)->getJson('/api/v1/me')->assertOk();
    }

    #[Test]
    public function desactivar_una_cuenta_cierra_su_sesion(): void
    {
        $token = $this->tokenFor('ALI-04');
        $this->member('ALI-04')->update(['is_active' => false]);

        $this->as($token)->getJson('/api/v1/me')->assertUnauthorized();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[Test]
    public function un_token_de_un_cliente_no_sirve_en_la_app(): void
    {
        $token = User::factory()->customer()->create()->createToken('web')->plainTextToken;

        $this->as($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    #[Test]
    public function cada_rol_entra_solo_a_lo_suyo(): void
    {
        Route::get('api/v1/_prueba/control', fn () => ['data' => true])
            ->middleware(['auth:sanctum', 'staff', 'role:control,management']);

        $this->as($this->tokenFor('JIM-01'))->getJson('/api/v1/_prueba/control')->assertOk();
        $this->as($this->tokenFor('DIR-08'))->getJson('/api/v1/_prueba/control')->assertOk();
        $this->as($this->tokenFor('ALI-04'))->getJson('/api/v1/_prueba/control')
            ->assertForbidden()
            ->assertExactJson(['message' => 'No tienes permiso para hacer esto.']);
    }

    #[Test]
    public function las_sesiones_pueden_vencer_si_se_configura(): void
    {
        config(['sanctum.expiration' => 60]);
        $token = $this->tokenFor('ALI-04');

        $this->travel(61)->minutes();

        $this->as($token)->getJson('/api/v1/me')->assertUnauthorized();
    }
}
