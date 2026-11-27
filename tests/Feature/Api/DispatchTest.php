<?php

namespace Tests\Feature\Api;

use App\Services\Sales\Stock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

class DispatchTest extends CampaignTestCase
{
    private function cashSale(array $lines = ['8107' => 2], string $total = '174.00', ?string $promisedAt = null): string
    {
        return $this->sell($this->sale($lines, $total, [
            'payment_method' => 'cash',
            ...($promisedAt ? ['delivery' => ['promised_at' => $promisedAt]] : []),
        ]))->assertCreated()->json('data.id');
    }

    /**
     * Una venta pagada de la tienda web (todavía no existe en la API): aparta
     * lo suyo, como las de la app.
     *
     * @param  array<string, int>  $products  código → cantidad
     * @param  array<string, int>  $combos  id → cantidad
     */
    private function webOrder(array $products = [], array $combos = [], ?string $paidAt = null): string
    {
        $id = (string) Str::uuid7();
        DB::table('orders')->insert([
            'id_order' => $id,
            'origin' => 'web',
            'status' => 'paid',
            'customer_name' => 'Carla Rojas',
            'customer_phone' => '70123456',
            'delivery_type' => 'delivery',
            'shipping_address' => 'Calle Sucre 245, Cochabamba',
            'paid_at' => $paidAt ?? now(),
            'created_at' => now(),
        ]);

        foreach ($products as $code => $quantity) {
            $product = $this->product((string) $code);
            DB::table('order_items')->insert([
                'order_id' => $id, 'product_id' => $product->getKey(), 'item_code' => $product->item_code,
                'product_name' => $product->name, 'unit_price_bs' => 10, 'quantity' => $quantity,
            ]);
            Stock::reserve($product->getKey(), $quantity);
        }

        foreach ($combos as $comboId => $quantity) {
            $combo = DB::table('combos')->where('id_combo', $comboId)->first();
            DB::table('order_items')->insert([
                'order_id' => $id, 'combo_id' => $comboId, 'item_code' => $combo->item_code,
                'product_name' => $combo->name, 'price_type' => 'combo', 'unit_price_bs' => $combo->price_bs,
                'quantity' => $quantity,
            ]);
            foreach (DB::table('combo_items')->where('combo_id', $comboId)->get() as $component) {
                Stock::reserve($component->product_id, $component->quantity * $quantity);
            }
        }

        return $id;
    }

    /** @param  array<string, int>  $products */
    private function combo(array $products): string
    {
        $id = (string) Str::uuid7();
        DB::table('combos')->insert([
            'id_combo' => $id, 'item_code' => 'CB-01', 'name' => 'Combo Navidad',
            'slug' => 'combo-navidad', 'price_bs' => 250,
        ]);

        foreach ($products as $code => $quantity) {
            DB::table('combo_items')->insert([
                'combo_id' => $id, 'product_id' => $this->product((string) $code)->getKey(), 'quantity' => $quantity,
            ]);
        }

        return $id;
    }

    private function dispatches(): TestResponse
    {
        return $this->api('ISA-03')->getJson('/api/v1/warehouse/dispatches')->assertOk();
    }

    private function advance(string $order, string $step, ?string $key = null): TestResponse
    {
        return $this->api('ISA-03')->postJson("/api/v1/warehouse/orders/{$order}/{$step}", [], $key
            ? ['Idempotency-Key' => $key]
            : []);
    }

    #[Test]
    public function lista_lo_pagado_de_todos_los_canales_con_lo_mas_urgente_primero(): void
    {
        $later = $this->cashSale(promisedAt: '2026-12-03T18:00:00-04:00');
        $sooner = $this->cashSale(promisedAt: '2026-12-03T13:00:00-04:00');
        $webNew = $this->webOrder(['8109' => 1]);
        $webOld = $this->webOrder(['8850' => 1], paidAt: '2026-12-03 08:00:00');
        $unpaid = $this->sell($this->sale())->json('data.id');
        $cancelled = $this->sell($this->sale())->json('data.id');
        $this->api('ALI-04')->postJson("/api/v1/orders/{$cancelled}/cancel")->assertOk();
        $this->travelTo('2026-12-03 14:00:00');

        $response = $this->dispatches();

        $this->assertSame([$sooner, $later, $webOld, $webNew], array_column($response->json('data'), 'order_id'));
        $this->assertNotContains($unpaid, array_column($response->json('data'), 'order_id'));
        $response
            ->assertJsonPath('data.0.is_late', true)
            ->assertJsonPath('data.1.is_late', false)
            ->assertJsonPath('data.2.is_late', false)
            ->assertJsonPath('data.0.items_label', 'Tomatodos de Messi × 2')
            ->assertJsonPath('data.0.lines', [['item_code' => '8107', 'name' => 'Tomatodos de Messi', 'quantity' => 2]])
            ->assertJsonPath('data.0.status', 'paid')
            ->assertJsonPath('data.0.delivery_type', 'pickup')
            ->assertJsonPath('data.0.shipping_address', null)
            ->assertJsonPath('data.0.customer_phone', '71234567')
            ->assertJsonPath('data.0.paid_at', '2026-12-03T12:00:00-04:00')
            ->assertJsonPath('data.0.promised_at', '2026-12-03T13:00:00-04:00')
            ->assertJsonPath('data.2.promised_at', null)
            ->assertJsonPath('data.2.shipping_address', 'Calle Sucre 245, Cochabamba');
    }

    #[Test]
    public function un_qr_confirmado_despues_de_la_hora_no_es_atraso_de_almacen(): void
    {
        $inTime = $this->sell($this->sale(with: ['delivery' => ['promised_at' => '2026-12-03T13:00:00-04:00']]))
            ->json('data.id');
        $this->confirmPayment($inTime);
        $paidLate = $this->sell($this->sale(with: ['delivery' => ['promised_at' => '2026-12-03T13:30:00-04:00']]))
            ->json('data.id');
        // «Se lo lleva ahora»: el efectivo se cobra al registrar, así que cuenta.
        $now = $this->cashSale(promisedAt: '2026-12-03T11:50:00-04:00');
        $this->travelTo('2026-12-03 14:00:00');
        $this->confirmPayment($paidLate);

        $late = collect($this->dispatches()->json('data'))->pluck('is_late', 'order_id');

        $this->assertSame([true, false, true], [$late[$inTime], $late[$paidLate], $late[$now]]);
    }

    #[Test]
    public function al_entregar_lo_apartado_sale_de_la_bodega_y_queda_en_el_kardex(): void
    {
        $order = $this->cashSale();
        $this->assertSame(['stock' => 48, 'reserved' => 2], $this->inventory('8107'));

        $this->advance($order, 'delivered')->assertConflict()->assertJsonPath('code', 'invalid_status');
        $this->advance($order, 'ready')->assertNoContent();
        $this->advance($order, 'ready')->assertConflict()->assertJsonPath('code', 'invalid_status');
        $this->dispatches()->assertJsonPath('data.0.status', 'ready');
        $this->travel(1)->hours();
        $this->advance($order, 'delivered')->assertNoContent();

        $this->assertSame(['stock' => 46, 'reserved' => 0], $this->inventory('8107'));
        $this->assertDatabaseHas('stock_movements', [
            'order_id' => $order, 'type' => 'sale', 'quantity' => -2,
            'source' => 'app', 'created_by' => $this->member('ISA-03')->getKey(),
        ]);
        $saved = DB::table('orders')->where('id_order', $order)->first();
        $this->assertSame('delivered', $saved->status);
        $this->assertNotNull($saved->ready_at);
        $this->assertNotNull($saved->dispatched_at);
        $this->assertNotNull($saved->delivered_at);
        $this->assertSame(
            ['paid', 'ready', 'delivered'],
            DB::table('order_status_history')->where('order_id', $order)->orderBy('id_order_status_history')
                ->pluck('to_status')->all(),
        );
        $this->dispatches()->assertJsonCount(0, 'data');

        // Quien vendió ve la línea de tiempo completa.
        $this->api('ALI-04')->getJson("/api/v1/orders/{$order}")
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.timeline.3.status', 'delivered')
            ->assertJsonPath('data.timeline.3.at', now()->toIso8601String());
    }

    #[Test]
    public function de_un_combo_salen_sus_componentes(): void
    {
        $combo = $this->combo(['8107' => 1, '8109' => 2]);
        $order = $this->webOrder(['8109' => 1], [$combo => 2]);

        $this->dispatches()
            ->assertJsonPath('data.0.items_label', 'Tomatodos de Ronaldo × 1 y 1 producto más')
            ->assertJsonPath('data.0.lines', [
                ['item_code' => '8109', 'name' => 'Tomatodos de Ronaldo', 'quantity' => 5],
                ['item_code' => '8107', 'name' => 'Tomatodos de Messi', 'quantity' => 2],
            ]);

        $this->advance($order, 'ready')->assertNoContent();
        $this->advance($order, 'delivered')->assertNoContent();

        $this->assertSame(['stock' => 46, 'reserved' => 0], $this->inventory('8107'));
        $this->assertSame(['stock' => 55, 'reserved' => 0], $this->inventory('8109'));
        $this->assertEqualsCanonicalizing(
            [-2, -5],
            DB::table('stock_movements')->where('order_id', $order)->pluck('quantity')->all(),
        );
    }

    #[Test]
    public function si_no_alcanza_lo_apartado_no_se_descuenta_nada(): void
    {
        $order = $this->cashSale(['8107' => 2, '8109' => 1], '263.00');
        $this->advance($order, 'ready')->assertNoContent();
        // Algo por fuera de la app soltó lo apartado.
        DB::table('inventory')->where('product_id', $this->product('8109')->getKey())->update(['reserved' => 0]);

        $this->advance($order, 'delivered')
            ->assertConflict()
            ->assertJsonPath('code', 'insufficient_stock')
            ->assertJsonPath('message', 'El stock apartado de Tomatodos de Ronaldo no alcanza para entregar este pedido.');

        $this->assertSame(['stock' => 48, 'reserved' => 2], $this->inventory('8107'));
        $this->assertDatabaseMissing('stock_movements', ['order_id' => $order]);
        $this->assertDatabaseHas('orders', ['id_order' => $order, 'status' => 'ready']);
    }

    #[Test]
    public function un_reintento_no_entrega_dos_veces_y_un_pedido_ajeno_no_existe(): void
    {
        $order = $this->cashSale();
        $this->advance($order, 'ready')->assertNoContent();
        $key = (string) Str::uuid();

        $this->advance($order, 'delivered', $key)->assertNoContent();
        $this->advance($order, 'delivered', $key)->assertNoContent()->assertHeader('Idempotent-Replayed', 'true');
        $this->advance((string) Str::uuid(), 'ready')->assertNotFound();

        $this->assertSame(1, DB::table('stock_movements')->where('order_id', $order)->count());
    }

    #[Test]
    public function solo_almacen_despacha(): void
    {
        $order = $this->cashSale();

        foreach (['ALI-04', 'JIM-01', 'DIR-08'] as $code) {
            $this->api($code)->getJson('/api/v1/warehouse/dispatches')->assertForbidden();
            $this->api($code)->postJson("/api/v1/warehouse/orders/{$order}/ready")->assertForbidden();
        }
    }

    #[Test]
    public function el_despacho_conserva_la_composicion_del_combo_que_se_vendio(): void
    {
        $product = $this->product('8107');
        $combo = $this->combo(['8107' => 2]);
        $order = $this->webOrder(combos: [$combo => 1]);
        DB::table('combo_items')->where('combo_id', $combo)->update(['quantity' => 3]);

        $this->advance($order, 'ready')->assertNoContent();
        $this->advance($order, 'delivered')->assertNoContent();

        $this->assertDatabaseHas('stock_movements', [
            'order_id' => $order, 'product_id' => $product->getKey(), 'quantity' => -2,
        ]);
    }
}
