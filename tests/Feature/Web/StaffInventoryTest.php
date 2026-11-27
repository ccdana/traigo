<?php

namespace Tests\Feature\Web;

use App\Mail\TwoFactorCodeMail;
use App\Services\Auth\MemberAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

class StaffInventoryTest extends WebTestCase
{
    private function signIn(string $code, string $pin = '1234'): TestResponse
    {
        return $this->from('/equipo/ingresar')->post('/equipo/ingresar', ['code' => $code, 'pin' => $pin]);
    }

    /** @param  array<string, mixed>  $data */
    private function move(array $data = []): TestResponse
    {
        $url = '/equipo/inventario/'.$this->product()->getKey();

        return $this->from($url)->post("{$url}/movimientos", [
            'type' => 'purchase',
            'quantity' => 12,
            'note' => null,
            'client_uuid' => (string) Str::uuid(),
            ...$data,
        ]);
    }

    #[Test]
    public function sin_sesion_el_panel_envia_al_ingreso(): void
    {
        $this->get('/equipo/inventario')->assertRedirect('/equipo/ingresar');
        $this->move()->assertRedirect('/equipo/ingresar');
    }

    #[Test]
    public function almacen_entra_con_su_codigo_y_pin(): void
    {
        $this->signIn('isa-03')->assertRedirect('/equipo/inventario');
        $this->assertAuthenticatedAs($this->member('ISA-03'), 'web');

        $this->get('/equipo/inventario?buscar=messi')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Tomatodos de Messi');
    }

    #[Test]
    public function un_pin_equivocado_o_un_rol_de_ventas_no_entra(): void
    {
        $this->signIn('ISA-03', '9999')->assertSessionHasErrors('code');
        $this->signIn('ALI-04')->assertSessionHasErrors('code');

        $this->assertGuest('web');
    }

    #[Test]
    public function jime_necesita_ademas_el_codigo_del_correo(): void
    {
        Mail::fake();

        $this->signIn('JIM-01')->assertRedirect('/equipo/verificar');
        $this->assertGuest('web');
        $code = Mail::sent(TwoFactorCodeMail::class)->last()->code;

        $this->from('/equipo/verificar')
            ->post('/equipo/verificar', ['code' => $code === '000000' ? '000001' : '000000'])
            ->assertSessionHasErrors('code');
        $this->assertGuest('web');

        $this->post('/equipo/verificar', ['code' => $code])->assertRedirect('/equipo/inventario');
        $this->assertAuthenticatedAs($this->member('JIM-01'), 'web');
    }

    #[Test]
    public function jime_mira_el_inventario_pero_no_lo_mueve(): void
    {
        $this->actingAs($this->member('JIM-01'), 'web');

        $this->get('/equipo/inventario/'.$this->product()->getKey())->assertOk()->assertDontSee('Registrar movimiento');
        $this->move()->assertForbidden();

        $this->assertSame(0, $this->level($this->product())->stock);
    }

    #[Test]
    public function una_entrada_sube_el_stock_y_queda_en_el_kardex_con_su_autor(): void
    {
        $this->actingAs($this->member('ISA-03'), 'web');

        $this->move()->assertSessionHasNoErrors()->assertSessionHas('status');

        $this->assertSame(12, $this->level($this->product())->stock);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product()->getKey(),
            'type' => 'purchase',
            'quantity' => 12,
            'source' => 'admin',
            'created_by' => $this->member('ISA-03')->getKey(),
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'inventory',
            'actor_id' => $this->member('ISA-03')->getKey(),
        ]);

        $this->get('/equipo/inventario/'.$this->product()->getKey())->assertSee('Entrada de mercadería')->assertSee('+12');
    }

    #[Test]
    public function enviar_dos_veces_el_mismo_formulario_registra_una_sola_vez(): void
    {
        $this->actingAs($this->member('ISA-03'), 'web');
        $key = (string) Str::uuid();

        $this->move(['client_uuid' => $key]);
        $this->move(['client_uuid' => $key]);

        $this->assertSame(12, $this->level($this->product())->stock);
        $this->assertSame(1, DB::table('stock_movements')->count());
    }

    #[Test]
    public function un_ajuste_pide_motivo_y_no_toca_lo_reservado(): void
    {
        $this->actingAs($this->member('ISA-03'), 'web');
        $this->stock($this->product(), 10, 4);

        $this->move(['type' => 'adjustment_out', 'quantity' => 2])->assertSessionHasErrors('note');
        $this->move(['type' => 'adjustment_out', 'quantity' => 7, 'note' => 'Rotura'])->assertSessionHasErrors('quantity');
        $this->assertSame(10, $this->level($this->product())->stock);

        $this->move(['type' => 'adjustment_out', 'quantity' => 6, 'note' => 'Rotura'])->assertSessionHasNoErrors();
        $this->assertSame(4, $this->level($this->product())->stock);
    }

    #[Test]
    public function las_ventas_no_se_pueden_registrar_a_mano(): void
    {
        $this->actingAs($this->member('ISA-03'), 'web');

        $this->move(['type' => 'sale'])->assertSessionHasErrors('type');
        $this->move(['quantity' => -5])->assertSessionHasErrors('quantity');
    }

    #[Test]
    public function se_filtra_por_estado_y_busqueda(): void
    {
        $this->actingAs($this->member('ISA-03'), 'web');
        $this->stock($this->product(), 3);
        $this->stock($this->product('8109'), 50);

        $this->get('/equipo/inventario?estado=low')
            ->assertViewHas('products', fn ($products) => $products->total() === 1)
            ->assertSee('Tomatodos de Messi');
        $this->get('/equipo/inventario?buscar=ronaldo')
            ->assertViewHas('products', fn ($products) => $products->total() === 1);
        $this->get('/equipo/inventario')
            ->assertViewHas('summary', fn ($summary) => $summary->products === 62
                && $summary->stock === 53
                && $summary->low_stock === 1
                && $summary->out_of_stock === 60);
    }

    #[Test]
    public function al_desactivar_la_cuenta_se_cierra_la_sesion_abierta(): void
    {
        $warehouse = $this->member('ISA-03');
        $this->actingAs($warehouse, 'web');
        $warehouse->update(['is_active' => false]);

        $this->get('/equipo/inventario')->assertRedirect('/equipo/ingresar');
        $this->assertGuest('web');
    }

    #[Test]
    public function cambiar_el_pin_tambien_cierra_las_sesiones_del_panel(): void
    {
        config(['session.driver' => 'database']);
        $warehouse = $this->member('ISA-03');
        DB::table('sessions')->insert([
            'id' => 'sesion-de-prueba',
            'user_id' => $warehouse->getKey(),
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        MemberAccess::resetPin($warehouse, '4321');

        $this->assertDatabaseMissing('sessions', ['id' => 'sesion-de-prueba']);
    }

    #[Test]
    public function la_sesion_del_panel_no_sirve_para_la_api_de_la_app(): void
    {
        $this->actingAs($this->member('ISA-03'), 'web');

        $this->getJson('/api/v1/me')->assertUnauthorized();
    }
}
