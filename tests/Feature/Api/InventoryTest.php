<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

class InventoryTest extends CampaignTestCase
{
    private function move(string $code, string $type, int $quantity, array $with = [], ?string $key = null): TestResponse
    {
        return $this->api('ISA-03')->postJson('/api/v1/inventory/movements', [
            'product_id' => $this->product($code)->getKey(),
            'type' => $type,
            'quantity' => $quantity,
            ...$with,
        ], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    /** @param  array<string, int>  $counted  código → unidades */
    private function countStock(array $counted, ?string $key = null): TestResponse
    {
        return $this->api('ISA-03')->postJson('/api/v1/inventory/counts', [
            'counted_on' => '2026-12-03',
            'items' => collect($counted)->map(fn (int $quantity, string $code) => [
                'product_id' => $this->product((string) $code)->getKey(),
                'counted_qty' => $quantity,
            ])->values()->all(),
            'note' => null,
        ], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    #[Test]
    public function la_primera_carga_y_los_ingresos_quedan_en_el_kardex(): void
    {
        $product = $this->product('8107')->getKey();
        DB::table('inventory')->where('product_id', $product)->delete();

        $this->move('8107', 'initial', 10)->assertCreated()->assertJsonPath('data', [
            'product_id' => $product, 'stock' => 10, 'reserved' => 0, 'available' => 10,
        ]);
        $this->move('8107', 'purchase', 5, ['note' => '  Caja nueva '])->assertCreated()
            ->assertJsonPath('data.stock', 15);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product, 'type' => 'purchase', 'quantity' => 5, 'note' => 'Caja nueva',
            'source' => 'app', 'created_by' => $this->member('ISA-03')->getKey(),
        ]);
    }

    #[Test]
    public function los_ajustes_piden_motivo_y_respetan_lo_reservado(): void
    {
        $this->sell($this->sale())->assertCreated(); // aparta 2 de 48

        $this->move('8107', 'adjustment_out', -1)
            ->assertUnprocessable()->assertJsonPath('code', 'note_required');
        $this->move('8107', 'adjustment_out', -47, ['note' => 'Rotas'])
            ->assertConflict()
            ->assertJsonPath('code', 'below_reserved')
            ->assertJsonPath('message', 'No puede quedar menos de las 2 unidades reservadas por pedidos.');
        $this->move('8107', 'adjustment_out', -46, ['note' => 'Rotas'])
            ->assertCreated()
            ->assertJsonPath('data.stock', 2)
            ->assertJsonPath('data.available', 0);

        foreach ([['purchase', -1], ['initial', -3], ['adjustment_in', -2], ['adjustment_out', 3]] as [$type, $quantity]) {
            $this->move('8107', $type, $quantity, ['note' => 'x'])
                ->assertUnprocessable()
                ->assertJsonPath('message', 'La cantidad no coincide con el tipo de movimiento.');
        }

        $this->move('8107', 'sale', -1)->assertJsonValidationErrors('type');
        $this->move('8107', 'purchase', 0)->assertJsonValidationErrors('quantity');
    }

    #[Test]
    public function el_conteo_guarda_lo_que_habia_en_bodega_y_no_cambia_el_stock(): void
    {
        $this->sell($this->sale())->assertCreated(); // lo apartado sigue en bodega

        $count = $this->countStock(['8109' => 58, '8107' => 48])
            ->assertCreated()
            ->assertJsonPath('data.counted_on', '2026-12-03')
            ->assertJsonPath('data.items', [
                [
                    'product_id' => $this->product('8107')->getKey(), 'item_code' => '8107',
                    'name' => 'Tomatodos de Messi', 'system_qty' => 48, 'counted_qty' => 48, 'adjusted' => false,
                ],
                [
                    'product_id' => $this->product('8109')->getKey(), 'item_code' => '8109',
                    'name' => 'Tomatodos de Ronaldo', 'system_qty' => 60, 'counted_qty' => 58, 'adjusted' => false,
                ],
            ])
            ->json('data.id');

        $this->assertSame(['stock' => 60, 'reserved' => 0], $this->inventory('8109'));

        $this->travel(1)->days();
        DB::table('inventory_counts')->where('id_inventory_count', $count)->update(['counted_on' => '2026-12-02']);
        $newest = $this->countStock(['8850' => 40])->assertCreated()->json('data.id');

        foreach (['ISA-03', 'JIM-01'] as $code) {
            $this->api($code)->getJson('/api/v1/inventory/counts')
                ->assertOk()
                ->assertJsonPath('data.0.id', $newest)
                ->assertJsonPath('data.1.id', $count);
        }
    }

    #[Test]
    public function la_diferencia_se_corrige_una_vez_y_justo_por_lo_contado(): void
    {
        $count = $this->countStock(['8109' => 58, '8107' => 50])->json('data.id');
        $with = ['note' => 'Conteo del jueves', 'inventory_count_id' => $count];

        $this->move('8109', 'adjustment_out', -1, $with)
            ->assertUnprocessable()->assertJsonPath('message', 'El ajuste no coincide con el conteo.');
        // Un ingreso no corrige un conteo.
        $this->move('8107', 'purchase', 2, $with)->assertJsonPath('message', 'El ajuste no coincide con el conteo.');
        $this->move('8850', 'adjustment_out', -1, $with)
            ->assertNotFound()->assertJsonPath('message', 'Ese producto no está en el conteo.');
        $this->move('8109', 'adjustment_out', -2, $with)->assertCreated()->assertJsonPath('data.stock', 58);
        $this->move('8109', 'adjustment_out', -2, $with)
            ->assertConflict()->assertJsonPath('code', 'already_adjusted');
        $this->move('8107', 'adjustment_in', 2, $with)->assertCreated()->assertJsonPath('data.stock', 50);

        $this->api('JIM-01')->getJson('/api/v1/inventory/counts')
            ->assertJsonPath('data.0.items.0.adjusted', true)
            ->assertJsonPath('data.0.items.1.adjusted', true)
            // El conteo queda como se hizo.
            ->assertJsonPath('data.0.items.1.system_qty', 60);
    }

    #[Test]
    public function un_reintento_no_suma_dos_veces(): void
    {
        $key = (string) Str::uuid();

        $this->move('8107', 'purchase', 5, key: $key)->assertCreated();
        $this->move('8107', 'purchase', 5, key: $key)->assertHeader('Idempotent-Replayed', 'true');
        Cache::flush();
        $this->move('8107', 'purchase', 5, key: $key)->assertCreated()->assertJsonPath('data.stock', 53);

        $countKey = (string) Str::uuid();
        $first = $this->countStock(['8107' => 53], $countKey)->json('data.id');
        $this->assertSame($first, $this->countStock(['8107' => 53], $countKey)->json('data.id'));
        $this->assertSame(1, DB::table('inventory_counts')->count());
    }

    #[Test]
    public function revisa_los_datos_del_conteo(): void
    {
        $product = $this->product('8107')->getKey();
        $send = fn (array $data) => $this->api('ISA-03')->postJson('/api/v1/inventory/counts', [
            'counted_on' => '2026-12-03',
            'items' => [['product_id' => $product, 'counted_qty' => 1]],
            ...$data,
        ], ['Idempotency-Key' => (string) Str::uuid()]);

        $send(['counted_on' => '2026-12-04'])->assertJsonPath('message', 'La fecha del conteo no puede ser futura.');
        $send(['items' => []])->assertJsonPath('message', 'Revisa las cantidades contadas.');
        $send(['items' => [['product_id' => $product, 'counted_qty' => -1]]])
            ->assertJsonPath('message', 'Revisa las cantidades contadas.');
        $send(['items' => array_fill(0, 2, ['product_id' => $product, 'counted_qty' => 1])])
            ->assertJsonPath('message', 'Hay un producto repetido en el conteo.');
        $send(['items' => [['product_id' => (string) Str::uuid(), 'counted_qty' => 1]]])
            ->assertJsonPath('message', 'Un producto del conteo ya no está en el catálogo.');
        $this->api('ISA-03')->postJson('/api/v1/inventory/counts', ['counted_on' => '2026-12-03', 'items' => [
            ['product_id' => $product, 'counted_qty' => 1],
        ]])->assertJsonPath('message', 'Falta la clave del envío.');

        $this->assertDatabaseCount('inventory_counts', 0);
    }

    #[Test]
    public function jime_mira_los_conteos_pero_no_mueve_el_stock(): void
    {
        $this->api('JIM-01')->postJson('/api/v1/inventory/movements', [])->assertForbidden();
        $this->api('JIM-01')->postJson('/api/v1/inventory/counts', [])->assertForbidden();

        foreach (['ALI-04', 'DIR-08'] as $code) {
            $this->api($code)->getJson('/api/v1/inventory/counts')->assertForbidden();
        }
    }
}
