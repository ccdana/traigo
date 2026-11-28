<?php

namespace Tests\Feature\Web;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

class WholesaleTest extends WebTestCase
{
    /** @param  array<string, mixed>  $data */
    private function quote(array $data = []): TestResponse
    {
        return $this->from('/por-mayor')->post('/por-mayor/cotizacion', [
            'name' => 'Comercial Andina',
            'phone' => '71234567',
            'message' => '50 tomatodos para regalo de empresa',
            ...$data,
        ]);
    }

    #[Test]
    public function muestra_los_minimos_reales_y_una_cuenta_de_ejemplo(): void
    {
        $this->get('/por-mayor')
            ->assertOk()
            ->assertSee('Compra por mayor')
            ->assertSee('Todos los modelos')
            ->assertSee('Ejemplo de cuenta por mayor')
            ->assertViewHas('example', function (array $example) {
                return $example['total_cents'] === array_sum(array_column($example['lines'], 'total_cents'))
                    && $example['savings_cents'] > 0;
            });
    }

    #[Test]
    public function sin_ventas_no_dice_que_algo_es_lo_mas_pedido(): void
    {
        $this->stock($this->product(), 100);

        $this->get('/por-mayor')
            ->assertSee('Con stock para pedir por mayor')
            ->assertDontSee('Los más pedidos por mayor');
    }

    #[Test]
    public function guarda_la_cotizacion_y_el_equipo_la_marca_respondida(): void
    {
        $this->quote()->assertSessionHasNoErrors()->assertSessionHas('status');
        $quote = DB::table('quote_requests')->first();
        $this->assertSame('new', $quote->status);

        $this->actingAs($this->member('DIR-08'), 'web');
        $this->get('/equipo/cotizaciones')->assertOk()->assertSee('Comercial Andina');
        $this->patch("/equipo/cotizaciones/{$quote->id_quote_request}", ['status' => 'answered']);

        $this->assertDatabaseHas('quote_requests', [
            'id_quote_request' => $quote->id_quote_request,
            'status' => 'answered',
            'assigned_to' => $this->member('DIR-08')->getKey(),
        ]);
    }

    #[Test]
    public function descarta_a_los_robots_y_los_datos_incompletos(): void
    {
        $this->quote(['website' => 'http://spam.test'])->assertSessionHas('status');
        $this->quote(['message' => ''])->assertSessionHasErrors('message');
        $this->quote(['phone' => 'abc'])->assertSessionHasErrors('phone');

        $this->assertDatabaseCount('quote_requests', 0);
    }

    #[Test]
    public function las_cotizaciones_no_se_ven_sin_sesion(): void
    {
        $this->quote();

        $this->get('/equipo/cotizaciones')->assertRedirect('/equipo/ingresar');
    }
}
