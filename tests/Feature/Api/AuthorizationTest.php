<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

/** Dirección autoriza o devuelve lo que Jime envía. */
class AuthorizationTest extends ControlTestCase
{
    private function management(string $path = '', string $code = 'DIR-08'): TestResponse
    {
        return $this->api($code)->getJson('/api/v1/management/authorizations'.($path === '' ? '' : "/{$path}"));
    }

    /** @param  array<string, mixed>  $data */
    private function decide(string $path, array $data = [], string $code = 'DIR-08'): TestResponse
    {
        return $this->api($code)->postJson("/api/v1/management/authorizations/{$path}", $data);
    }

    /** La semana 1 enviada: Kim cerrada a mano y Sistemas 2 sin sus entregas. */
    private function sentWeek(): void
    {
        $this->readyToClose(except: ['KIM-05'], deliverSystems: false);
        $this->deliverAll('SIS-06');
        $this->sendWeek(closePending: ['KIM-05' => 'No envió sus cotizaciones', 'SIS-07' => 'Sin entregas'])
            ->assertOk();
    }

    #[Test]
    public function la_bandeja_junta_todo_lo_que_espera(): void
    {
        $this->act('products/'.$this->product('8107')->getKey().'/price-changes', ['price_unit' => '89'])->assertCreated();
        $this->sentWeek();

        $response = $this->management()->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(['price_change', 'weekly_bonus'], array_column($response->json('data'), 'type'));
        $response
            ->assertJsonPath('data.0.title', 'Precio de Tomatodos de Messi')
            ->assertJsonPath('data.0.amount', null)
            ->assertJsonPath('data.1', [
                'type' => 'weekly_bonus', 'id' => '1', 'title' => 'Bono semana 1–6 dic', 'requested_by' => 'Jime',
                'requested_at' => '2026-12-07T10:00:00-04:00', 'amount' => '1050.00',
            ]);

        $this->management(code: 'JIM-01')->assertForbidden();
        $this->decide('weekly_bonus/1/approve', code: 'JIM-01')->assertForbidden();
    }

    #[Test]
    public function el_bono_muestra_a_quien_se_paga_y_por_que_no_a_los_demas(): void
    {
        $this->loadActuals('AIL-02', 0.5);
        $this->readyToClose(except: ['AIL-02', 'KIM-05'], deliverSystems: false);
        $this->deliverAll('SIS-06');
        $this->sendWeek(closePending: ['KIM-05' => 'No envió sus cotizaciones', 'SIS-07' => 'Sin entregas'])
            ->assertOk();

        $this->management('weekly_bonus/1')
            ->assertOk()
            ->assertJsonPath('data.amount', '750.00')
            ->assertJsonPath('data.pays.0', ['name' => 'Isaí', 'amount' => '300.00'])
            ->assertJsonPath('data.unpaid', [
                ['name' => 'Ailén', 'amount' => '300.00', 'reason' => 'KPI 50 % de 90 %'],
                ['name' => 'Kim', 'amount' => '300.00', 'reason' => 'Cerrado por Jime: No envió sus cotizaciones'],
                ['name' => 'Sistemas 2', 'amount' => '150.00', 'reason' => 'Cerrado por Jime: Sin entregas'],
            ])
            ->assertJsonPath('data.checks', [
                'Cobros conciliados: 1 de 1 pagos confirmados',
                'KPI con evidencia revisada',
                'Duplicados depurados',
            ])
            ->assertJsonPath('data.can_approve', true)
            ->assertJsonPath('data.blocked_reason', null);

        $this->decide('weekly_bonus/1/approve')->assertNoContent();

        $this->assertDatabaseHas('weekly_closings', [
            'week_id' => $this->week()->getKey(), 'status' => 'authorized', 'direction_approved' => true,
            'authorized_by' => $this->member('DIR-08')->getKey(),
        ]);
        $this->decide('weekly_bonus/1/approve')
            ->assertNotFound()->assertJsonPath('message', 'Eso ya no espera autorización.');
        $this->act('bonus/1/members/'.$this->member('ISA-03')->getKey().'/paid')->assertOk();
    }

    #[Test]
    public function devolver_el_cierre_deja_corregir_y_reenviar(): void
    {
        $this->sentWeek();

        $this->decide('weekly_bonus/1/return')->assertJsonPath('message', 'Escribe el motivo.');
        $this->decide('weekly_bonus/1/return', ['reason' => 'Carga los valores de Kim'])->assertNoContent();

        $this->control('weekly-closing?week=1')
            ->assertJsonPath('data.status', 'returned')
            ->assertJsonPath('data.return_reason', 'Carga los valores de Kim');
        $this->assertDatabaseCount('weekly_member_results', 0);
        // Quien se cerró a mano puede volver a subir lo suyo.
        $this->api('KIM-05')->getJson('/api/v1/me/week?week=1')->assertJsonPath('data.accepts_evidence', true);
        $this->loadActuals('KIM-05');
        $this->sendWeek(closePending: ['SIS-07' => 'Sin entregas'])->assertOk();
        $this->management('weekly_bonus/1')->assertJsonPath('data.amount', '1350.00');
    }

    #[Test]
    public function si_una_devolucion_cambia_lo_que_se_paga_no_se_puede_autorizar(): void
    {
        $this->sentWeek();
        $big = DB::table('orders')->where('customer_name', 'Mayorista web')->value('id_order');
        $this->act("orders/{$big}/return", ['reason' => 'Devuelto'])->assertNoContent();

        $this->management('weekly_bonus/1')
            ->assertJsonPath('data.amount', '1050.00')
            ->assertJsonPath('data.can_approve', false)
            ->assertJsonPath('data.blocked_reason', 'Desde el envío cambió lo cobrado de la semana (Bs 41.600 → Bs 0)'
                .' y con eso lo que se paga. Devuélvelo para que Jime lo envíe otra vez.');
        $this->decide('weekly_bonus/1/approve')->assertConflict()->assertJsonPath('code', 'collected_changed');

        $this->decide('weekly_bonus/1/return', ['reason' => 'Hubo una devolución'])->assertNoContent();
        $this->sendWeek(closePending: ['KIM-05' => 'x', 'SIS-07' => 'y'])->assertOk();
        $this->management('weekly_bonus/1')->assertJsonPath('data.amount', '0.00')->assertJsonPath('data.can_approve', true);
    }

    #[Test]
    public function si_no_cambia_lo_que_se_paga_se_autoriza_con_aviso(): void
    {
        $small = $this->webPayment('100', '2026-12-04 11:00:00');
        $this->sentWeek();
        $this->act("orders/{$small}/return", ['reason' => 'Devuelto'])->assertNoContent();

        $this->management('weekly_bonus/1')
            ->assertJsonPath('data.can_approve', true)
            ->assertJsonPath('data.checks.3', 'Desde el envío cambió lo cobrado (Bs 41.700 → Bs 41.600); lo que se paga no cambia.');
        $this->decide('weekly_bonus/1/approve')->assertNoContent();
    }

    #[Test]
    public function las_comisiones_del_mes_se_autorizan_o_se_devuelven(): void
    {
        $this->setRate('8107', 5);
        $sale = $this->proofSale();
        $this->confirm($sale['payment']);
        $this->travelTo('2027-01-02 10:00:00');
        $this->act('commissions/2026-12/send')->assertOk();

        $this->management()->assertJsonPath('data.0', [
            'type' => 'commissions', 'id' => '2026-12', 'title' => 'Comisiones de diciembre', 'requested_by' => 'Jime',
            'requested_at' => '2027-01-02T10:00:00-04:00', 'amount' => '8.70',
        ]);
        $this->management('commissions/2026-12')
            ->assertJsonPath('data.pays', [['name' => 'Alison', 'amount' => '8.70']])
            ->assertJsonPath('data.checks', ['1 venta cobrada']);

        $this->decide('commissions/2026-12/return', ['reason' => 'Falta el cobro de Kim'])->assertNoContent();
        $this->control('commissions?month=2026-12')
            ->assertJsonPath('data.status', 'returned')
            ->assertJsonPath('data.return_reason', 'Falta el cobro de Kim')
            ->assertJsonPath('data.total', '8.70');
        $this->act('commissions/2026-12/send')->assertOk();
        $this->decide('commissions/2026-12/approve')->assertNoContent();
        $this->act('commissions/2026-12/paid')->assertOk()->assertJsonPath('data.status', 'paid');
        $this->management('commissions/2026-12')->assertNotFound();
    }

    #[Test]
    public function aprobar_un_precio_lo_aplica_y_las_ventas_sin_porcentaje_lo_toman(): void
    {
        $paid = $this->proofSale();
        $this->confirm($paid['payment']);
        $returned = $this->proofSale('transfer');
        $this->confirm($returned['payment']);
        $this->act("orders/{$returned['order']}/return", ['reason' => 'Defecto'])->assertNoContent();
        $product = $this->product('8107')->getKey();
        $this->act("products/{$product}/price-changes", ['price_unit' => '89', 'commission_pct' => '5'])->assertCreated();
        $id = DB::table('price_change_requests')->value('id_price_change_request');

        $this->management("price_change/{$id}")
            ->assertOk()
            ->assertJsonPath('data.changes', [
                ['label' => 'Minorista', 'before' => 'Bs 87', 'after' => 'Bs 89'],
                ['label' => 'Comisión', 'before' => 'Por definir', 'after' => '5 %'],
            ])
            ->assertJsonPath('data.checks.0', 'Las ventas sin % de este producto que no se liquidaron toman esta comisión (hoy, 2).')
            ->assertJsonPath('data.can_approve', true);
        $this->decide("price_change/{$id}/approve")->assertNoContent();

        $this->assertDatabaseHas('products', ['id_product' => $product, 'price_unit_bs' => 89, 'commission_pct' => 5]);
        // Las ventas ya registradas conservan su precio.
        $this->assertDatabaseHas('order_items', ['order_id' => $paid['order'], 'unit_price_bs' => 87]);
        $this->assertSame(
            ['-8.70', '8.70', '8.70'],
            DB::table('commissions')->whereIn('order_id', [$paid['order'], $returned['order']])
                ->orderBy('amount_bs')->pluck('amount_bs')->all(),
        );
        $this->control('audit')->assertJsonPath(
            'data.0.description',
            'Autorizó el precio de Tomatodos de Messi: Bs 87 → Bs 89 · 2 ventas sin % lo tomaron',
        );
        // Una sola línea por la aprobación: no repite el cambio del producto.
        $this->assertSame(
            [],
            preg_grep('/^Cambió (el precio|la comisión) de Tomatodos/u', array_column($this->control('audit')->json('data'), 'description')),
        );
        $this->decide("price_change/{$id}/approve")->assertNotFound();
    }

    #[Test]
    public function un_precio_no_aprobado_no_cambia_nada_y_uno_que_ya_no_cabe_se_devuelve(): void
    {
        $product = $this->product('8107')->getKey();
        $this->act("products/{$product}/price-changes", ['price_unit' => '83'])->assertCreated();
        $id = DB::table('price_change_requests')->value('id_price_change_request');

        // Después de pedirlo, el precio por mayor subió por fuera.
        DB::table('products')->where('id_product', $product)->update(['price_wholesale_bs' => 85]);
        $this->management("price_change/{$id}")
            ->assertJsonPath('data.can_approve', false)
            ->assertJsonPath('data.blocked_reason', 'El producto cambió desde que Jime lo pidió. '
                .'El precio por mayor no puede ser mayor que el de unidad. Devuélvelo para que lo revise.');
        $this->decide("price_change/{$id}/approve")->assertConflict()->assertJsonPath('code', 'change_outdated');

        $this->decide("price_change/{$id}/return", ['reason' => 'Revisa el mayorista'])->assertNoContent();
        $this->assertDatabaseHas('price_change_requests', [
            'id_price_change_request' => $id, 'status' => 'rejected', 'decision_note' => 'Revisa el mayorista',
        ]);
        $this->assertDatabaseHas('products', ['id_product' => $product, 'price_unit_bs' => 87]);
        $this->act("products/{$product}/price-changes", ['price_unit' => '90'])->assertCreated();
    }

    #[Test]
    public function una_promocion_solo_se_aprueba_si_no_baja_del_precio_por_mayor(): void
    {
        $promotion = function (int $percent, string $name): string {
            $id = (string) Str::uuid7();
            DB::table('promotions')->insert([
                'id_promotion' => $id, 'name' => $name, 'discount_type' => 'percentage', 'discount_value' => $percent,
                'calendar_day' => $percent === 5 ? 1 : 2, 'starts_at' => '2026-12-01', 'ends_at' => '2026-12-02',
            ]);
            DB::table('promotion_products')->insert([
                'promotion_id' => $id, 'product_id' => $this->product('8107')->getKey(),
            ]);

            return $id;
        };
        $fine = $promotion(5, 'Puerta 1');
        $deep = $promotion(20, 'Puerta 2');

        $this->management("promotion/{$fine}")
            ->assertJsonPath('data.requested_by', 'Tienda web')
            ->assertJsonPath('data.changes.0.after', '5 %')
            ->assertJsonPath('data.changes.2.after', 'desde el 1 dic hasta el 2 dic')
            ->assertJsonPath('data.can_approve', true);
        $this->decide("promotion/{$fine}/approve")->assertNoContent();
        $this->assertDatabaseHas('promotions', ['id_promotion' => $fine, 'status' => 'approved']);

        $this->management("promotion/{$deep}")
            ->assertJsonPath('data.can_approve', false)
            ->assertJsonPath('data.blocked_reason',
                'La oferta dejaría «Tomatodos de Messi» en Bs 69,60, por debajo del precio por mayor (Bs 82).');
        $this->decide("promotion/{$deep}/approve")->assertConflict();
        $this->decide("promotion/{$deep}/return", ['reason' => 'Muy rebajada'])->assertNoContent();
        $this->control('audit')->assertJsonPath('data.0.description', 'No aprobó la promoción «Puerta 2»: Muy rebajada');
    }

    #[Test]
    public function lo_que_no_espera_no_existe(): void
    {
        foreach (['weekly_bonus/1', 'weekly_bonus/999', 'commissions/2026-12', 'price_change/abc', 'promotion/'.Str::uuid()] as $path) {
            $this->management($path)->assertNotFound();
        }
    }
}
