<?php

namespace Tests\Feature\Api;

use PHPUnit\Framework\Attributes\Test;

/** Los comandos con los que se da el PIN y el correo en el servidor. */
class MemberCommandsTest extends ApiTestCase
{
    #[Test]
    public function poner_un_pin_cierra_las_sesiones_y_desbloquea(): void
    {
        $this->member('JIM-01')->update(['locked_until' => now()->addHour()]);
        $token = $this->tokenFor('JIM-01');

        $this->artisan('traigo:pin', ['codigo' => 'jim-01'])
            ->expectsQuestion('PIN nuevo de Jimena (4 números)', '4821')
            ->expectsQuestion('Repite el PIN', '4821')
            ->assertSuccessful();

        $this->as($token)->getJson('/api/v1/me')->assertUnauthorized();
        $this->login('JIM-01', '1234')->assertUnauthorized();
        $this->travel(2)->minutes();
        $this->login('JIM-01', '4821')->assertOk();
    }

    #[Test]
    public function el_pin_tiene_cuatro_numeros_y_se_escribe_dos_veces(): void
    {
        $this->artisan('traigo:pin', ['codigo' => 'ALI-04'])
            ->expectsQuestion('PIN nuevo de Alison (4 números)', '12a4')
            ->assertFailed();

        $this->artisan('traigo:pin', ['codigo' => 'ALI-04'])
            ->expectsQuestion('PIN nuevo de Alison (4 números)', '4821')
            ->expectsQuestion('Repite el PIN', '4822')
            ->assertFailed();

        $this->login('ALI-04')->assertOk();
    }

    #[Test]
    public function guarda_un_correo_valido_y_sin_repetir(): void
    {
        $this->artisan('traigo:correo', ['codigo' => 'JIM-01', 'correo' => 'jime@ejemplo.com'])
            ->assertSuccessful();
        $this->assertSame('jime@ejemplo.com', $this->member('JIM-01')->email);

        $this->artisan('traigo:correo', ['codigo' => 'DIR-08', 'correo' => 'JIME@ejemplo.com'])
            ->assertFailed();
        $this->artisan('traigo:correo', ['codigo' => 'DIR-08', 'correo' => 'sin-arroba'])
            ->assertFailed();
        $this->artisan('traigo:correo', ['codigo' => 'NADIE-9', 'correo' => 'a@ejemplo.com'])
            ->assertFailed();
    }
}
