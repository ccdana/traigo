<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

/** Precios autorizados: Jime propone solo lo que cambia. */
class PriceTest extends ControlTestCase
{
    /** @param  array<string, mixed>  $changes */
    private function propose(array $changes, string $code = '8107'): TestResponse
    {
        return $this->act("products/{$this->product($code)->getKey()}/price-changes", $changes);
    }

    #[Test]
    public function la_ficha_muestra_lo_vigente_y_lo_que_espera(): void
    {
        $id = $this->product('8107')->getKey();

        $this->control("products/{$id}")
            ->assertOk()
            ->assertJsonPath('data.item_code', '8107')
            ->assertJsonPath('data.category', 'Tomatodos')
            ->assertJsonPath('data.current', [
                'price_unit' => '87.00', 'price_wholesale' => '82.00', 'wholesale_min_qty' => 24,
                'commission_pct' => null, 'cost' => null,
            ])
            ->assertJsonPath('data.pending_change', null);
        $this->control("products/{$id}", 'DIR-08')->assertOk();
        $this->control("products/{$id}", 'ALI-04')->assertForbidden();
        $this->control('products/00000000-0000-0000-0000-000000000000')->assertNotFound();
    }

    #[Test]
    public function se_guarda_solo_lo_que_cambia_y_uno_a_la_vez(): void
    {
        $this->propose(['price_unit' => '89', 'price_wholesale' => '82.00', 'reason' => ' Subió el flete '])
            ->assertCreated()
            ->assertJsonPath('data.pending_change.proposed', ['price_unit' => '89.00'])
            ->assertJsonPath('data.pending_change.requested_by', 'Jimena')
            ->assertJsonPath('data.pending_change.reason', 'Subió el flete')
            ->assertJsonPath('data.current.price_unit', '87.00');

        $request = DB::table('price_change_requests')->first();
        $this->assertNull($request->price_wholesale_bs);
        $this->assertSame('87.00', json_decode($request->previous_values)->price_unit);

        $this->propose(['cost' => '40'])->assertConflict()->assertJsonPath('code', 'change_pending');
        // Mientras tanto se vende con los precios de siempre.
        $this->sell($this->sale())->assertCreated();
    }

    #[Test]
    public function revisa_que_el_cambio_tenga_sentido(): void
    {
        foreach ([
            [[], 'No hay cambios.'],
            [['price_unit' => '87.00', 'wholesale_min_qty' => 24], 'No hay cambios.'],
            [['price_unit' => '0'], 'El precio no puede ser cero.'],
            [['price_wholesale' => '90'], 'El precio por mayor no puede ser mayor que el de unidad.'],
            [['price_unit' => '80'], 'El precio por mayor no puede ser mayor que el de unidad.'],
            [['wholesale_min_qty' => 1], 'El mínimo por mayor es desde 2 unidades.'],
            [['commission_pct' => '101'], 'La comisión va de 0 a 100 %.'],
            [['commission_pct' => '5.123'], 'La comisión va de 0 a 100 %.'],
            [['cost' => '-1'], 'Revisa el costo.'],
        ] as [$changes, $message]) {
            $this->propose($changes)->assertUnprocessable()->assertJsonPath('message', $message);
        }

        DB::table('products')->where('id_product', $this->product('8107')->getKey())
            ->update(['price_wholesale_bs' => null, 'wholesale_min_qty' => null]);
        $this->propose(['price_wholesale' => '80'])
            ->assertJsonPath('message', 'El precio por mayor y su mínimo van juntos.');
        $this->propose(['price_wholesale' => '80', 'wholesale_min_qty' => 12])->assertCreated();

        $this->act('products/'.$this->product('8107')->getKey().'/price-changes', ['cost' => '1'], 'DIR-08')
            ->assertForbidden();
    }
}
