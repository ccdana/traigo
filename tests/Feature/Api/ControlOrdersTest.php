<?php

namespace Tests\Feature\Api;

use App\Services\Sales\Stock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

/** Devoluciones y duplicados. */
class ControlOrdersTest extends ControlTestCase
{
    private function webOrder(string $name = 'Lucía Rojas'): string
    {
        $id = (string) Str::uuid7();
        $product = $this->product('8850');
        DB::table('orders')->insert([
            'id_order' => $id, 'origin' => 'web', 'status' => 'payment_review', 'customer_name' => $name,
            'subtotal_bs' => 89, 'total_bs' => 89, 'created_at' => now()->subMinute(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $id, 'product_id' => $product->getKey(),
            'item_code' => $product->item_code, 'product_name' => $product->name, 'unit_price_bs' => 89, 'quantity' => 1,
        ]);
        Stock::reserve($product->getKey(), 1);
        DB::table('payments')->insert([
            'order_id' => $id, 'method' => 'transfer', 'status' => 'submitted', 'amount_bs' => 89,
            'proof_url' => 'comprobantes/web.jpg', 'submitted_at' => now(), 'created_at' => now(),
        ]);

        return $id;
    }

    private function deliver(string $order): void
    {
        $this->api('ISA-03')->postJson("/api/v1/warehouse/orders/{$order}/ready")->assertNoContent();
        $this->api('ISA-03')->postJson("/api/v1/warehouse/orders/{$order}/delivered")->assertNoContent();
    }

    #[Test]
    public function jime_busca_en_todos_los_pedidos_tambien_los_de_la_web(): void
    {
        $web = $this->webOrder();
        $this->travel(1)->minutes();
        $mine = $this->cashSale('KIM-05');
        $number = DB::table('orders')->where('id_order', $mine)->value('order_number');

        $this->control('orders')
            ->assertOk()
            ->assertJsonPath('data.0.id', $mine)
            ->assertJsonPath('data.0.seller_name', 'Kim')
            ->assertJsonPath('data.0.payment_method', 'cash')
            ->assertJsonPath('data.1.id', $web)
            ->assertJsonPath('data.1.seller_name', null)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 2);

        $this->control('orders?search=LUCIA')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $web);
        $this->control('orders?search='.strtolower($number))->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine);
        $this->control('orders?search=%25')->assertJsonCount(0, 'data');

        $this->control("orders/{$web}")
            ->assertOk()
            ->assertJsonPath('data.commission', null)
            ->assertJsonPath('data.can_cancel', false)
            ->assertJsonPath('data.can_upload_proof', false)
            ->assertJsonPath('data.can_return', false)
            ->assertJsonPath('data.can_mark_duplicate', true)
            ->assertJsonPath('data.void_reason', null);
        $this->control('orders/'.Str::uuid())->assertNotFound();
    }

    #[Test]
    public function devolver_lo_entregado_revierte_el_cobro_la_comision_y_vuelve_al_stock(): void
    {
        $this->setRate('8107', 5);
        $proof = $this->proofSale();
        $this->confirm($proof['payment']);
        $this->deliver($proof['order']);
        $this->assertSame(['stock' => 46, 'reserved' => 0], $this->inventory('8107'));
        $this->control("orders/{$proof['order']}")->assertJsonPath('data.can_return', true)
            ->assertJsonPath('data.can_mark_duplicate', false);

        $this->travelTo('2026-12-10 10:00:00');
        $this->act("orders/{$proof['order']}/return", ['reason' => ''])->assertJsonPath('message', 'Escribe el motivo.');
        $this->act("orders/{$proof['order']}/return", ['reason' => 'Llegó golpeado'])->assertNoContent();

        $this->assertSame(['stock' => 48, 'reserved' => 0], $this->inventory('8107'));
        $this->assertDatabaseHas('stock_movements', [
            'order_id' => $proof['order'], 'type' => 'sale_return', 'quantity' => 2, 'note' => 'Llegó golpeado',
        ]);
        $this->assertDatabaseHas('payments', ['id_payment' => $proof['payment'], 'status' => 'reversed']);
        $this->assertDatabaseHas('v_commission_sources', [
            'order_id' => $proof['order'], 'kind' => 'sale', 'status' => 'earned', 'amount_bs' => '8.70',
        ]);
        $this->assertDatabaseHas('v_commission_sources', [
            'order_id' => $proof['order'], 'kind' => 'adjustment', 'status' => 'earned',
            'base_bs' => '-174.00', 'amount_bs' => '-8.70',
        ]);
        $this->control('summary')->assertJsonPath('data.collected', '0.00');
        $this->control("orders/{$proof['order']}")
            ->assertJsonPath('data.status', 'returned')
            ->assertJsonPath('data.void_reason', 'Llegó golpeado')
            ->assertJsonPath('data.payment.status', 'reversed')
            ->assertJsonPath('data.can_return', false);
        $this->act("orders/{$proof['order']}/return", ['reason' => 'x'])
            ->assertConflict()->assertJsonPath('code', 'invalid_status');
    }

    #[Test]
    public function devolver_antes_de_entregar_suelta_lo_apartado(): void
    {
        $order = $this->cashSale();

        $this->act("orders/{$order}/return", ['reason' => 'Se arrepintió'])->assertNoContent();

        $this->assertSame(['stock' => 48, 'reserved' => 0], $this->inventory('8107'));
        $this->assertDatabaseHas('payments', ['order_id' => $order, 'status' => 'rejected']);
        $this->assertDatabaseHas('v_commission_sources', ['order_id' => $order, 'status' => 'cancelled']);
        $this->assertDatabaseMissing('v_commission_sources', ['order_id' => $order, 'kind' => 'adjustment']);
        $this->assertDatabaseMissing('stock_movements', ['order_id' => $order]);
    }

    #[Test]
    public function un_duplicado_se_anula_y_queda_ligado_al_original(): void
    {
        $original = $this->cashSale();
        $copy = $this->proofSale('qr', 'DUP-1');
        $number = DB::table('orders')->where('id_order', $original)->value('order_number');

        $this->act("orders/{$copy['order']}/duplicate", ['original_order_id' => $copy['order']])
            ->assertJsonPath('message', 'Elige como original un pedido vigente.');
        $this->act("orders/{$copy['order']}/duplicate", ['original_order_id' => $original])->assertNoContent();

        $this->assertSame(['stock' => 48, 'reserved' => 2], $this->inventory('8107'));
        $this->assertDatabaseHas('orders', [
            'id_order' => $copy['order'], 'status' => 'cancelled', 'cancel_reason' => 'duplicate', 'duplicate_of_id' => $original,
        ]);
        $this->assertDatabaseHas('payments', ['order_id' => $copy['order'], 'status' => 'rejected']);
        $this->assertDatabaseHas('v_commission_sources', ['order_id' => $copy['order'], 'status' => 'cancelled']);
        $this->control("orders/{$copy['order']}")
            ->assertJsonPath('data.duplicate_of_number', $number)
            ->assertJsonPath('data.can_mark_duplicate', false);

        // Un anulado no sirve de original.
        $this->act("orders/{$original}/duplicate", ['original_order_id' => $copy['order']])
            ->assertUnprocessable();
        $copyNumber = DB::table('orders')->where('id_order', $copy['order'])->value('order_number');
        $this->control('weekly-closing')
            ->assertJsonPath('data.checks.duplicates_cleared.detail', "1 duplicado quitado ({$copyNumber})");
    }

    #[Test]
    public function con_el_cobro_confirmado_no_es_duplicado_sino_devolucion(): void
    {
        $original = $this->cashSale('KIM-05');
        $proof = $this->proofSale();
        $this->confirm($proof['payment']);

        $this->act("orders/{$proof['order']}/duplicate", ['original_order_id' => $original])
            ->assertConflict()
            ->assertJsonPath('message', 'Con un cobro confirmado o ya entregado, registra una devolución.');
    }

    #[Test]
    public function solo_jime_devuelve_o_anula(): void
    {
        $order = $this->cashSale();

        foreach (['DIR-08', 'ALI-04', 'ISA-03'] as $code) {
            $this->act("orders/{$order}/return", ['reason' => 'x'], $code)->assertForbidden();
            $this->act("orders/{$order}/duplicate", ['original_order_id' => $order], $code)->assertForbidden();
        }
    }
}
