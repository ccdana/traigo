<?php

namespace Tests\Feature\Api;

use App\Models\Order;
use App\Services\Control\Commissions;
use Database\Seeders\LocalStockSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

class OrdersTest extends SalesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeSecond();
    }

    #[Test]
    public function registra_una_venta_y_aparta_el_stock(): void
    {
        $response = $this->sell($this->sale())->assertCreated();

        $alison = $this->member('ALI-04');
        $order = Order::query()->sole();
        $response
            ->assertJsonPath('data.id', $order->getKey())
            ->assertJsonPath('data.number', $order->fresh()->order_number)
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.items_label', 'Tomatodos de Messi × 2')
            ->assertJsonPath('data.total', '174.00')
            ->assertJsonPath('data.payment_method', 'qr')
            ->assertJsonPath('data.origin', 'whatsapp')
            ->assertJsonPath('data.customer_phone', '71234567')
            ->assertJsonPath('data.delivery', [
                'type' => 'pickup',
                'address' => null,
                'promised_at' => now()->addHours(3)->toIso8601String(),
            ])
            ->assertJsonPath('data.lines.0', [
                'product_id' => $this->product('8107')->getKey(),
                'item_code' => '8107',
                'name' => 'Tomatodos de Messi',
                'category' => 'Tomatodos',
                'image_url' => null,
                'quantity' => 2,
                'price_type' => 'unit',
                'unit_price' => '87.00',
                'line_total' => '174.00',
            ])
            ->assertJsonPath('data.payment', null)
            ->assertJsonPath('data.timeline.0.status', 'pending_payment')
            ->assertJsonPath('data.timeline.0.at', now()->toIso8601String())
            ->assertJsonPath('data.timeline.1', ['status' => 'paid', 'at' => null])
            ->assertJsonPath('data.commission', ['status' => 'pending', 'amount' => null])
            ->assertJsonPath('data.can_cancel', true)
            ->assertJsonPath('data.can_upload_proof', true);

        $this->assertStringStartsWith('N-', $response->json('data.number'));
        $this->assertSame(['stock' => 48, 'reserved' => 2], $this->inventory('8107'));
        $this->assertSame($alison->getKey(), $order->seller_id);
        $this->assertDatabaseHas('v_commission_sources', [
            'order_id' => $order->getKey(), 'seller_id' => $alison->getKey(),
            'base_bs' => '174.00', 'rate_pct' => null, 'status' => 'pending',
        ]);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->getKey(), 'to_status' => 'pending_payment', 'changed_by' => $alison->getKey(),
        ]);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->getKey(), 'status' => 'pending', 'amount_bs' => '174.00',
        ]);
    }

    #[Test]
    public function cada_producto_va_por_mayor_al_llegar_a_su_propio_minimo(): void
    {
        $this->sell($this->sale(['8107' => 24, '8109' => 1], '2057.00'))
            ->assertCreated()
            ->assertJsonPath('data.items_label', 'Tomatodos de Messi × 24 y 1 producto más')
            ->assertJsonPath('data.lines.0.price_type', 'wholesale')
            ->assertJsonPath('data.lines.0.unit_price', '82.00')
            ->assertJsonPath('data.lines.0.line_total', '1968.00')
            ->assertJsonPath('data.lines.1.price_type', 'unit')
            ->assertJsonPath('data.lines.1.unit_price', '89.00');
    }

    #[Test]
    public function guarda_la_comision_de_cada_producto_como_estaba_al_vender(): void
    {
        $this->product('8107')->forceFill(['commission_pct' => 3.33])->save();

        $this->sell($this->sale(['8107' => 1], '87.00'))
            ->assertCreated()
            // 87 × 3,33 % = 2,8971: se redondea como en la base.
            ->assertJsonPath('data.commission', ['status' => 'pending', 'amount' => '2.90']);

        $this->assertDatabaseHas('order_items', ['commission_rate_pct' => '3.33']);
    }

    #[Test]
    public function si_el_precio_cambio_no_registra_nada_y_dice_el_total(): void
    {
        $this->sell($this->sale(['8107' => 2], '170.00'))
            ->assertConflict()
            ->assertExactJson([
                'message' => 'Los precios cambiaron. Revisa el total antes de registrar.',
                'code' => 'price_changed',
                'data' => ['total' => '174.00'],
            ]);

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, $this->inventory('8107')['reserved']);
    }

    #[Test]
    public function no_vende_mas_de_lo_disponible(): void
    {
        $this->sell($this->sale(['8107' => 47], '3854.00'))->assertCreated();

        $this->sell($this->sale(['8107' => 2], '174.00'))
            ->assertConflict()
            ->assertExactJson([
                'message' => 'Solo quedan 1 de Tomatodos de Messi.',
                'code' => 'insufficient_stock',
                'data' => ['product_id' => $this->product('8107')->getKey(), 'available' => 1],
            ]);

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(47, $this->inventory('8107')['reserved']);
    }

    #[Test]
    public function no_vende_productos_desactivados(): void
    {
        $sale = $this->sale();
        $this->product('8107')->forceFill(['is_active' => false])->save();

        $this->sell($sale)->assertConflict()->assertJsonPath('code', 'product_unavailable');
    }

    #[Test]
    public function la_entrega_acordada_no_puede_haber_pasado(): void
    {
        $past = fn (int $minutes) => $this->sale(with: [
            'delivery' => ['promised_at' => now()->subMinutes($minutes)->toIso8601String()],
        ]);

        $this->sell($past(31))->assertUnprocessable()->assertJsonPath('code', 'promised_in_past');
        $this->sell($past(20))->assertCreated();
    }

    #[Test]
    public function revisa_los_datos_y_los_dice_en_espanol(): void
    {
        $this->sell($this->sale(with: ['delivery' => ['type' => 'delivery']]))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Falta la dirección de entrega.');

        $this->sell($this->sale(with: ['payment_method' => 'card']))->assertJsonValidationErrors('payment_method');
        $this->sell($this->sale(with: ['origin' => 'web']))->assertJsonValidationErrors('origin');
        $this->sell(['lines' => []] + $this->sale())->assertJsonValidationErrors('lines');

        $duplicated = $this->sale();
        $duplicated['lines'][] = $duplicated['lines'][0];
        $this->sell($duplicated)->assertJsonValidationErrors('lines.0.product_id');

        $this->api('ALI-04')->postJson('/api/v1/orders', $this->sale())
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Falta la clave del envío.');

        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function todo_el_equipo_vende_y_solo_ve_sus_pedidos_y_comisiones(): void
    {
        DB::table('products')->where('item_code', '8107')->update(['commission_pct' => 5]);
        foreach (['ISA-03', 'AIL-02', 'SIS-06', 'JIM-01', 'DIR-08'] as $code) {
            $order = $this->sell($this->sale(), $code)->assertCreated()->json('data.id');
            $this->api($code)->getJson('/api/v1/orders')->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $order);
            $this->api('ALI-04')->getJson("/api/v1/orders/{$order}")->assertNotFound();
            $this->api($code)->getJson('/api/v1/me/commissions')->assertOk()->assertJsonPath('data.amount', '0.00');
            Commissions::earn($order, now());
            $this->api($code)->getJson('/api/v1/me/commissions')->assertOk()->assertJsonPath('data.amount', '8.70');
        }
        $this->api('ALI-04')->getJson('/api/v1/me/commissions')->assertOk()->assertJsonPath('data.amount', '0.00');

        $this->sell($this->sale(), 'KIM-05')->assertCreated();
    }

    #[Test]
    public function un_reintento_no_registra_dos_veces(): void
    {
        $key = (string) Str::uuid();
        $sale = $this->sale();

        $first = $this->sell($sale, key: $key)->assertCreated()->json('data.id');
        $this->sell($sale, key: $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        // Aunque se pierda la respuesta guardada, la base recuerda la clave.
        Cache::flush();
        $this->sell($sale, key: $key)->assertCreated()->assertJsonPath('data.id', $first);

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(2, $this->inventory('8107')['reserved']);

        Cache::flush();
        $this->sell($sale, 'KIM-05', $key)->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
    }

    #[Test]
    public function la_venta_en_efectivo_nace_pagada_y_por_confirmar(): void
    {
        $this->sell($this->sale(with: ['payment_method' => 'cash']))
            ->assertCreated()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payment.method', 'cash')
            ->assertJsonPath('data.payment.status', 'submitted')
            ->assertJsonPath('data.payment.submitted_at', now()->toIso8601String())
            ->assertJsonPath('data.timeline.1', ['status' => 'paid', 'at' => now()->toIso8601String()])
            ->assertJsonPath('data.commission.status', 'pending')
            ->assertJsonPath('data.can_cancel', false)
            ->assertJsonPath('data.can_upload_proof', false);

        $id = Order::query()->sole()->getKey();
        $this->api('ALI-04')->postJson("/api/v1/orders/{$id}/cancel")
            ->assertConflict()
            ->assertJsonPath('code', 'not_cancellable');
    }

    #[Test]
    public function cada_quien_ve_solo_sus_ventas_la_mas_nueva_primero(): void
    {
        $first = $this->sell($this->sale())->json('data.id');
        $this->travel(1)->minutes();
        $cash = $this->sell($this->sale(with: ['payment_method' => 'cash']))->json('data.id');
        $this->travel(1)->minutes();
        $other = $this->sell($this->sale(), 'KIM-05')->json('data.id');

        $this->api('ALI-04')->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 2])
            ->assertJsonPath('data.0.id', $cash)
            ->assertJsonPath('data.1.id', $first)
            ->assertJsonPath('data.1.payment_method', 'qr')
            ->assertJsonMissingPath('data.0.lines');

        // «Pagados» incluye los que ya están listos.
        Order::query()->findOrFail($cash)->update(['status' => 'ready']);
        $this->api('ALI-04')->getJson('/api/v1/orders?status=paid')->assertJsonPath('data.0.id', $cash);
        $this->api('ALI-04')->getJson('/api/v1/orders?status=pending_payment')->assertJsonCount(1, 'data');
        $this->api('ALI-04')->getJson('/api/v1/orders?status=delivered')->assertJsonCount(0, 'data');
        $this->api('ALI-04')->getJson('/api/v1/orders?status=cancelled')->assertUnprocessable();

        $this->api('ALI-04')->getJson("/api/v1/orders/{$other}")
            ->assertNotFound()
            ->assertJsonPath('message', 'No encontramos ese pedido.');
        $this->api('ALI-04')->postJson("/api/v1/orders/{$other}/cancel")->assertNotFound();
        $this->api('ALI-04')->getJson('/api/v1/orders/123')->assertNotFound();
    }

    #[Test]
    public function cancelar_libera_el_stock_y_anula_la_comision(): void
    {
        $id = $this->sell($this->sale())->json('data.id');

        $this->api('ALI-04')->postJson("/api/v1/orders/{$id}/cancel", ['reason' => 'Se arrepintió'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.payment', null)
            ->assertJsonPath('data.commission.status', 'cancelled')
            ->assertJsonPath('data.can_cancel', false)
            ->assertJsonPath('data.can_upload_proof', false);

        $this->assertSame(['stock' => 48, 'reserved' => 0], $this->inventory('8107'));
        $this->assertDatabaseHas('orders', ['id_order' => $id, 'cancel_reason' => 'Se arrepintió']);
        $this->assertDatabaseHas('v_commission_sources', ['order_id' => $id, 'status' => 'cancelled']);

        // El historial guarda quién lo canceló.
        $entry = \DB::table('audit_logs')->where('entity_type', 'orders')->where('entity_id', $id)->latest('id_audit_log')->first();
        $this->assertSame($this->member('ALI-04')->getKey(), $entry->actor_id);

        $this->api('ALI-04')->postJson("/api/v1/orders/{$id}/cancel")
            ->assertConflict()
            ->assertJsonPath('code', 'not_cancellable');
    }

    #[Test]
    public function el_stock_de_prueba_no_se_carga_dos_veces(): void
    {
        $this->seed(LocalStockSeeder::class);

        $this->assertSame(48, $this->inventory('8107')['stock']);
        $this->assertSame(1, \DB::table('stock_movements')->where('product_id', $this->product('8107')->getKey())->count());
    }
}
