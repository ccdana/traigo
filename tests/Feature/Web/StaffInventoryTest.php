<?php

namespace Tests\Feature\Web;

use App\Mail\TwoFactorCodeMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

class StaffInventoryTest extends WebTestCase
{
    #[Test]
    public function sin_sesion_el_panel_envia_al_ingreso(): void
    {
        $this->get('/equipo/estadisticas')->assertRedirect('/equipo/ingresar');
        $this->get('/equipo/inventario')->assertRedirect('/equipo/ingresar');
    }

    #[Test]
    public function solo_direccion_puede_iniciar_sesion_en_el_panel(): void
    {
        foreach (['ISA-03', 'JIM-01', 'ALI-04', 'KIM-05', 'AIL-02', 'SIS-06'] as $code) {
            $this->from('/equipo/ingresar')->post('/equipo/ingresar', ['code' => $code, 'pin' => '1234'])->assertSessionHasErrors('code');
            $this->assertGuest('web');
        }
    }

    #[Test]
    public function direccion_necesita_pin_y_codigo_del_correo(): void
    {
        Mail::fake();
        $this->from('/equipo/ingresar')->post('/equipo/ingresar', ['code' => 'DIR-08', 'pin' => '9999'])->assertSessionHasErrors('code');
        $this->post('/equipo/ingresar', ['code' => 'DIR-08', 'pin' => '1234'])->assertRedirect('/equipo/verificar');
        $this->assertGuest('web');
        $code = Mail::sent(TwoFactorCodeMail::class)->last()->code;
        $this->post('/equipo/verificar', ['code' => $code === '000000' ? '000001' : '000000'])->assertSessionHasErrors('code');
        $this->post('/equipo/verificar', ['code' => $code])->assertRedirect('/equipo/estadisticas');
        $this->assertAuthenticatedAs($this->member('DIR-08'), 'web');
    }

    #[Test]
    public function las_sesiones_anteriores_de_otros_roles_ya_no_abren_el_panel(): void
    {
        foreach (['ISA-03', 'JIM-01', 'ALI-04'] as $code) {
            $this->actingAs($this->member($code), 'web');
            $this->get('/equipo/inventario')->assertRedirect('/equipo/ingresar');
            $this->assertGuest('web');
        }
    }

    #[Test]
    public function direccion_consulta_inventario_sin_cambiar_los_permisos_de_almacen(): void
    {
        $this->actingAs($this->member('DIR-08'), 'web');
        $id = $this->product()->getKey();
        $this->get('/equipo/inventario/'.$id)->assertOk()->assertDontSee('Registrar movimiento')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->post('/equipo/inventario/'.$id.'/movimientos', [
            'type' => 'purchase', 'quantity' => 12, 'client_uuid' => (string) Str::uuid(),
        ])->assertForbidden();
        $this->assertSame(0, $this->level($this->product())->stock);
        // Sigue siendo el permiso original utilizado por la app.
        $this->assertTrue($this->member('ISA-03')->role->canMoveStock());
        $this->assertFalse($this->member('DIR-08')->role->canMoveStock());
    }

    #[Test]
    public function se_filtra_por_estado_y_busqueda(): void
    {
        $this->actingAs($this->member('DIR-08'), 'web');
        $this->stock($this->product(), 3);
        $this->stock($this->product('8109'), 50);
        $this->get('/equipo/inventario?estado=low')->assertViewHas('products', fn ($products) => $products->total() === 1)->assertSee('Tomatodos de Messi');
        $this->get('/equipo/inventario?buscar=ronaldo')->assertViewHas('products', fn ($products) => $products->total() === 1);
        $this->get('/equipo/inventario')->assertViewHas('summary', fn ($summary) => $summary->products === 62 && $summary->stock === 53 && $summary->low_stock === 1 && $summary->out_of_stock === 60);
    }

    #[Test]
    public function al_desactivar_direccion_se_cierra_su_sesion(): void
    {
        $admin = $this->member('DIR-08');
        $this->actingAs($admin, 'web');
        $admin->update(['is_active' => false]);
        $this->get('/equipo/estadisticas')->assertRedirect('/equipo/ingresar');
        $this->assertGuest('web');
    }

    #[Test]
    public function la_sesion_del_panel_no_sirve_para_la_api(): void
    {
        $this->actingAs($this->member('DIR-08'), 'web');
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }
}
