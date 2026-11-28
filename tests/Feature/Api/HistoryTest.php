<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

/** El historial: una línea clara por cada hecho importante. */
class HistoryTest extends ControlTestCase
{
    /** @return list<array{actor_name: string, description: string}> lo más nuevo primero */
    private function history(): array
    {
        return array_map(
            fn (array $entry) => ['actor_name' => $entry['actor_name'], 'description' => $entry['description']],
            $this->control('audit')->assertOk()->json('data'),
        );
    }

    private function number(string $orderId): string
    {
        return DB::table('orders')->where('id_order', $orderId)->value('order_number');
    }

    #[Test]
    public function cuenta_lo_importante_en_palabras_y_esconde_lo_automatico(): void
    {
        $this->setRate('8107', 5);
        $proof = $this->proofSale(reference: 'QR-1');
        $rejected = $this->proofSale('transfer');
        $cancelled = $this->sell($this->sale())->json('data.id');
        $this->api('ALI-04')->postJson("/api/v1/orders/{$cancelled}/cancel", ['reason' => 'Se arrepintió'])->assertOk();
        $cash = $this->cashSale();
        // Vender y apartar no salen en el historial; cancelar y lo cambiado
        // directo en la base (el % de arriba), sí.
        $this->assertSame([
            ['actor_name' => 'Alison', 'description' => 'Canceló el pedido '.$this->number($cancelled).': Se arrepintió'],
            ['actor_name' => 'Sistema', 'description' => 'Cambió la comisión de Tomatodos de Messi: por definir → 5 %'],
        ], $this->history());

        $this->confirm($proof['payment']);
        $this->act("payments/{$rejected['payment']}/reject", ['reason' => 'Borroso'])->assertOk();
        $this->assertSame([
            ['actor_name' => 'Jimena', 'description' => 'Rechazó el comprobante de '.$this->number($rejected['order']).': Borroso'],
            ['actor_name' => 'Jimena', 'description' => 'Confirmó el pago '.$this->number($proof['order']).' (Bs 174)'],
        ], array_slice($this->history(), 0, 2));

        $closing = $this->sendCash('2026-12-03');
        $this->act("cash-closings/{$closing}/observe", ['note' => 'Falta vuelto'])->assertOk();
        $this->sendCash('2026-12-03');
        $this->act("cash-closings/{$closing}/validate")->assertOk();
        $result = $this->resultId('ALI-04', 'Registro semanal de ventas');
        $this->act("kpi-results/{$result}/validate", ['actual' => 1.5])->assertOk();
        $this->act("orders/{$proof['order']}/return", ['reason' => 'Defecto'])->assertNoContent();
        $this->act("orders/{$rejected['order']}/duplicate", ['original_order_id' => $cash])->assertNoContent();

        $this->assertSame([
            ['actor_name' => 'Jimena', 'description' => 'Quitó '.$this->number($rejected['order']).' por duplicado de '.$this->number($cash)],
            ['actor_name' => 'Jimena', 'description' => 'Registró la devolución de '.$this->number($proof['order']).': Defecto'],
            ['actor_name' => 'Jimena', 'description' => 'Cargó el valor real de «Registro semanal de ventas» de Alison: sin cargar → 1,5'],
            ['actor_name' => 'Jimena', 'description' => 'Validó el cierre de caja del jueves 3 dic'],
            ['actor_name' => 'Isaí', 'description' => 'Reenvió corregido el cierre de caja del jueves 3 dic'],
            ['actor_name' => 'Jimena', 'description' => 'Pidió corregir la caja del jueves 3 dic: Falta vuelto'],
            ['actor_name' => 'Isaí', 'description' => 'Envió el cierre de caja del jueves 3 dic'],
        ], array_slice($this->history(), 0, 7));
        $this->assertCount(11, $this->history());
    }

    #[Test]
    public function el_cierre_semanal_la_autorizacion_el_pago_y_las_comisiones(): void
    {
        $this->setRate('8107', 5);
        $sale = $this->proofSale();
        $this->confirm($sale['payment']);
        $this->readyToClose(except: ['SIS-07']);
        $this->sendWeek(closePending: ['SIS-07' => 'Sin datos'])->assertOk();
        $this->api('DIR-08')->postJson('/api/v1/management/authorizations/weekly_bonus/1/approve')->assertNoContent();
        $this->act('bonus/1/members/'.$this->member('AIL-02')->getKey().'/paid')->assertOk();
        $this->travelTo('2027-01-02 10:00:00');
        $this->act('commissions/2026-12/send')->assertOk();
        $this->api('DIR-08')->postJson('/api/v1/management/authorizations/commissions/2026-12/return', ['reason' => 'Revisa'])
            ->assertNoContent();

        $lines = array_column($this->history(), 'description');

        $this->assertSame([
            'Devolvió las comisiones de diciembre: Revisa',
            'Envió las comisiones de diciembre a Dirección',
            'Marcó pagado el bono de Ailén (Bs 300)',
            'Autorizó el bono de la semana 1–6 dic (Bs 1.350)',
            'Envió el cierre de la semana 1–6 dic a Dirección',
            'Cerró a Brandon como «no cumple»: Sin datos',
        ], array_slice($lines, 0, 6));
        $this->assertSame(['Miguel', 'Jimena'], array_slice(array_column($this->history(), 'actor_name'), 0, 2));
    }

    #[Test]
    public function el_stock_los_conteos_las_entregas_y_los_cambios_por_fuera(): void
    {
        $deliverable = $this->submitDeliverable();
        $this->act("deliverables/{$deliverable}/reject", ['note' => 'No abre'])->assertOk();
        $product = $this->product('8109')->getKey();
        $move = fn (string $type, int $quantity, array $with = []) => $this->api('ISA-03')->postJson('/api/v1/inventory/movements', [
            'product_id' => $product, 'type' => $type, 'quantity' => $quantity, ...$with,
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $move('purchase', 6);
        $move('adjustment_out', -2, ['note' => 'Rotas']);
        $this->api('ISA-03')->postJson('/api/v1/inventory/counts', [
            'counted_on' => '2026-12-03', 'items' => [['product_id' => $product, 'counted_qty' => 60]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        DB::table('products')->where('id_product', $product)->update(['price_unit_bs' => 90]);

        $this->assertSame([
            ['actor_name' => 'Sistema', 'description' => 'Cambió el precio de Tomatodos de Ronaldo: Bs 89 → Bs 90'],
            ['actor_name' => 'Isaí', 'description' => 'Hizo un conteo: 1 producto con diferencia'],
            ['actor_name' => 'Isaí', 'description' => 'Ajustó el stock de 8109: −2 (Rotas)'],
            ['actor_name' => 'Isaí', 'description' => 'Cargó el stock de 8109: +6'],
            ['actor_name' => 'Jimena', 'description' => 'No validó «Tienda web publicada, navegable y con catálogo» de Dana: No abre'],
        ], $this->history());
    }

    #[Test]
    public function se_pagina_de_a_30_y_no_se_puede_ver_sin_permiso(): void
    {
        $result = $this->resultId('ALI-04', 'Registro semanal de ventas');
        for ($value = 1; $value <= 31; $value++) {
            $this->act("kpi-results/{$result}/validate", ['actual' => $value])->assertOk();
        }

        $first = $this->control('audit')->assertJsonPath('meta', [
            'current_page' => 1, 'last_page' => 2, 'per_page' => 30, 'total' => 31,
        ]);
        $second = $this->control('audit?page=2')->assertJsonCount(1, 'data');
        $ids = array_merge(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id'));
        $this->assertCount(31, array_unique($ids));

        $this->control('audit', 'DIR-08')->assertOk();
        $this->control('audit', 'ALI-04')->assertForbidden();
    }
}
