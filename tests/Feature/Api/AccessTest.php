<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/** Los accesos del equipo y el estado de los respaldos. */
class AccessTest extends ControlTestCase
{
    #[Test]
    public function jime_ve_las_cuentas_del_equipo(): void
    {
        $this->control('members')
            ->assertOk()
            ->assertJsonCount(6, 'data')
            ->assertJsonPath('data.0', [
                'id' => $this->member('AIL-02')->getKey(), 'code' => 'AIL-02', 'name' => 'Ailén',
                'title' => 'Contenido y lives',
            ]);
        $this->control('members', 'DIR-08')->assertForbidden();
    }

    #[Test]
    public function un_pin_nuevo_desbloquea_cierra_sesiones_y_no_queda_escrito(): void
    {
        $member = $this->member('KIM-05');
        $member->forceFill(['failed_login_attempts' => 5, 'locked_until' => now()->addMinutes(10)])->save();
        $token = $this->tokenFor('KIM-05');

        $this->act("members/{$member->getKey()}/pin", ['pin' => '12a4'])
            ->assertUnprocessable()->assertJsonPath('message', 'El PIN tiene 4 números.');
        $this->act("members/{$member->getKey()}/pin", ['pin' => '4821'])->assertNoContent();

        $this->as($token)->getJson('/api/v1/me')->assertUnauthorized();
        $this->login('KIM-05')->assertUnauthorized();
        $this->login('KIM-05', '4821')->assertOk();

        $audit = DB::table('audit_logs')->where('action', 'pin_reset')->get();
        $this->assertCount(1, $audit);
        $this->assertStringNotContainsString('4821', json_encode(DB::table('audit_logs')->get()));
        $this->control('audit')->assertJsonPath('data.0.description', 'Puso un PIN nuevo a Kim');
    }

    #[Test]
    public function un_celular_perdido_se_cierra_sin_cambiar_el_pin(): void
    {
        $token = $this->tokenFor('ALI-04');

        $this->act('members/'.$this->member('ALI-04')->getKey().'/revoke-sessions')->assertNoContent();

        $this->as($token)->getJson('/api/v1/me')->assertUnauthorized();
        $this->login('ALI-04')->assertOk();
        $this->control('audit')->assertJsonPath('data.0.description', 'Cerró las sesiones de Alison');
    }

    #[Test]
    public function las_cuentas_de_jime_y_direccion_no_se_cambian_desde_la_app(): void
    {
        foreach (['JIM-01', 'DIR-08'] as $code) {
            $this->act('members/'.$this->member($code)->getKey().'/pin', ['pin' => '4821'])
                ->assertNotFound()->assertJsonPath('message', 'Solo se cambian las cuentas del equipo.');
            $this->act('members/'.$this->member($code)->getKey().'/revoke-sessions')->assertNotFound();
        }

        $this->act('members/'.$this->member('KIM-05')->getKey().'/pin', ['pin' => '4821'], 'DIR-08')->assertForbidden();
    }

    #[Test]
    public function el_servidor_anota_los_respaldos_con_su_comando(): void
    {
        $this->control('system-status')
            ->assertOk()
            ->assertJsonPath('data', ['last_backup_at' => null, 'restore_tested_at' => null]);

        $this->artisan('traigo:respaldo')->assertSuccessful();
        $this->artisan('traigo:respaldo restauracion --fecha="2026-11-29 16:00"')->assertSuccessful();
        $this->artisan('traigo:respaldo otra')->assertFailed();
        $this->artisan('traigo:respaldo hecho --fecha=ayer')->assertFailed();
        $this->artisan('traigo:respaldo hecho --fecha="2027-01-01 00:00"')->assertFailed();

        $this->control('system-status')->assertJsonPath('data', [
            'last_backup_at' => '2026-12-03T12:00:00-04:00',
            'restore_tested_at' => '2026-11-29T16:00:00-04:00',
        ]);
        $this->travel(1)->days();
        $this->artisan('traigo:respaldo')->assertSuccessful();
        $this->control('system-status')->assertJsonPath('data.last_backup_at', '2026-12-04T12:00:00-04:00');
        // Los respaldos no llenan el historial.
        $this->control('audit')->assertJsonPath('meta.total', 0);
        $this->control('system-status', 'DIR-08')->assertForbidden();
    }
}
