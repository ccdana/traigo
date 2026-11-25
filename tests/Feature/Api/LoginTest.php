<?php

namespace Tests\Feature\Api;

use App\Models\User;
use PHPUnit\Framework\Attributes\Test;

class LoginTest extends ApiTestCase
{
    #[Test]
    public function el_equipo_entra_con_codigo_y_pin_y_recibe_su_sesion(): void
    {
        $response = $this->login('ali-04');

        $alison = $this->member('ALI-04');
        $response->assertOk()
            ->assertJsonPath('data.member', [
                'id' => $alison->getKey(),
                'code' => 'ALI-04',
                'name' => 'Alison',
                'role' => 'seller',
                'title' => 'Ventas por comisión',
            ]);

        $token = $response->json('data.token');
        $this->as($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.member.code', 'ALI-04');
        $this->assertNotNull($alison->refresh()->last_login_at);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'Pruebas']);
    }

    #[Test]
    public function un_pin_o_un_codigo_malo_responden_lo_mismo(): void
    {
        foreach ([['ALI-04', '9999'], ['NADIE-9', '1234']] as [$code, $pin]) {
            $this->login($code, $pin)
                ->assertUnauthorized()
                ->assertExactJson(['message' => 'Código o PIN incorrectos.']);
        }
    }

    #[Test]
    public function una_cuenta_desactivada_o_sin_pin_no_entra(): void
    {
        $this->member('ALI-04')->update(['is_active' => false]);
        $this->member('KIM-05')->update(['pin_hash' => null]);

        $this->login('ALI-04')->assertUnauthorized();
        $this->login('KIM-05')->assertUnauthorized();
    }

    #[Test]
    public function cinco_pin_fallidos_bloquean_la_cuenta_quince_minutos(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->login('ALI-04', '0000')->assertUnauthorized();
        }

        $this->login('ALI-04', '0000')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After', '900')
            ->assertJsonPath('message', 'Demasiados intentos. Espera 15 minutos.');

        // Ni con el PIN correcto mientras dure.
        $this->travel(2)->minutes();
        $this->login('ALI-04')->assertTooManyRequests();

        $this->travel(14)->minutes();
        $this->login('ALI-04')->assertOk();
        $this->assertSame(0, $this->member('ALI-04')->failed_login_attempts);
    }

    #[Test]
    public function un_ingreso_correcto_vuelve_a_cero_los_fallos(): void
    {
        $this->login('ALI-04', '0000')->assertUnauthorized();
        $this->assertSame(1, $this->member('ALI-04')->failed_login_attempts);

        $this->login('ALI-04')->assertOk();

        $this->assertSame(0, $this->member('ALI-04')->failed_login_attempts);
    }

    #[Test]
    public function se_limitan_los_intentos_por_codigo_e_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login('NADIE-9')->assertUnauthorized();
        }

        $this->login('NADIE-9')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', 'Demasiados intentos. Espera un momento.');

        // Otro código desde la misma IP todavía puede.
        $this->login('ALI-04')->assertOk();
    }

    #[Test]
    public function los_datos_que_faltan_se_dicen_en_espanol(): void
    {
        $this->postJson('/api/v1/auth/login', ['code' => 'ALI-04', 'pin' => '1234'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Falta el nombre del celular.')
            ->assertJsonValidationErrors('device_name');
    }

    #[Test]
    public function un_cliente_de_la_tienda_no_entra_a_la_app(): void
    {
        User::factory()->customer()->create();

        $this->postJson('/api/v1/auth/login', [
            'code' => '',
            'pin' => '1234',
            'device_name' => 'Pruebas',
        ])->assertUnprocessable();
    }
}
