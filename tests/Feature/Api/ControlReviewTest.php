<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/** El resumen, la bandeja y las validaciones de Jime. */
class ControlReviewTest extends ControlTestCase
{
    private const REGISTRY = 'Registro semanal de ventas';

    #[Test]
    public function el_resumen_y_la_bandeja_muestran_lo_que_falta_lo_mas_antiguo_primero(): void
    {
        $this->travelTo('2026-12-03 09:00:00');
        $deliverable = $this->submitDeliverable();
        $this->travel(1)->hours();
        $proof = $this->proofSale();
        $this->travel(1)->hours();
        $evidence = $this->submitEvidence('ALI-04', self::REGISTRY);
        $this->travel(1)->hours();
        $this->cashSale('KIM-05');
        $closing = $this->sendCash('2026-12-03');
        // El efectivo no se revisa en «Pagos»: va con la caja.

        $this->control('summary')
            ->assertOk()
            ->assertJsonPath('data.as_of', now()->toIso8601String())
            ->assertJsonPath('data.week.label', '1–6 dic')
            ->assertJsonPath('data.collected', '0.00')
            ->assertJsonPath('data.pending', [
                'payments' => 1, 'evidence' => 1, 'cash_closings' => 1, 'deliverables' => 1,
            ])
            ->assertJsonCount(6, 'data.members')
            ->assertJsonPath('data.members.0.name', 'Ailén')
            ->assertJsonPath('data.members.4.note', '0 de 4 entregas validadas')
            ->assertJsonPath('data.members.4.fund_share_rate', '0.10')
            ->assertJsonPath('data.bonus', [
                'level' => 'none', 'prize' => '0.00', 'payable' => '0.00',
                'unpaid_failing' => '0.00', 'unpaid_pending' => '0.00',
            ]);

        $response = $this->control('pending')->assertOk();
        $this->assertSame(
            ['deliverable', 'payment', 'evidence', 'cash_closing'],
            array_column($response->json('data'), 'type'),
        );
        $response
            ->assertJsonPath('data.0.id', $deliverable)
            ->assertJsonPath('data.0.title', 'Dana')
            ->assertJsonPath('data.1', [
                'type' => 'payment', 'id' => $proof['payment'], 'title' => 'Mariela Quispe · Alison',
                'subtitle' => 'QR', 'created_at' => '2026-12-03T10:00:00-04:00', 'amount' => '174.00',
            ])
            ->assertJsonPath('data.2.id', $evidence)
            ->assertJsonPath('data.2.title', 'Alison · Registro semanal de ventas')
            ->assertJsonPath('data.2.subtitle', '1 evidencia · meta 1')
            ->assertJsonPath('data.3.id', $closing)
            ->assertJsonPath('data.3.title', 'Isaí · cierre del jueves 3 dic')
            ->assertJsonPath('data.3.subtitle', 'QR, transferencia y efectivo')
            // Lo cobrado del día: el QR por revisar y el efectivo.
            ->assertJsonPath('data.3.amount', '348.00');

        $this->control('pending?type=evidence')->assertJsonCount(1, 'data');
        $this->control('pending?type=otro')->assertUnprocessable();
    }

    #[Test]
    public function direccion_ve_el_resumen_pero_no_valida_nada(): void
    {
        $this->control('summary', 'DIR-08')->assertOk();
        $this->control('compliance', 'DIR-08')->assertOk();
        $this->control('bonus', 'DIR-08')->assertOk();
        $this->control('commissions', 'DIR-08')->assertOk();

        foreach (['pending', 'weekly-closing', 'orders', 'deliverables'] as $path) {
            $this->control($path, 'DIR-08')->assertForbidden();
        }

        foreach (['ALI-04', 'ISA-03'] as $code) {
            $this->control('summary', $code)->assertForbidden();
            $this->control('pending', $code)->assertForbidden();
        }
    }

    #[Test]
    public function confirmar_un_pago_lo_cobra_y_gana_la_comision(): void
    {
        $proof = $this->proofSale(reference: '0045-8812');

        $this->control("payments/{$proof['payment']}")
            ->assertOk()
            ->assertJsonPath('data.order.seller_name', 'Alison')
            ->assertJsonPath('data.order.items_label', 'Tomatodos de Messi × 2')
            ->assertJsonPath('data.method', 'qr')
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.reference_unique', true)
            ->assertJsonPath('data.destination_account', 'Traigo');

        $submitted = DB::table('payments')->where('id_payment', $proof['payment'])->value('submitted_at');
        $this->travel(2)->hours();
        $this->act("payments/{$proof['payment']}/confirm", ['amount_matches' => true, 'destination_ok' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $payment = DB::table('payments')->where('id_payment', $proof['payment'])->first();
        $this->assertSame($submitted, $payment->received_at);
        $this->assertSame($submitted, DB::table('v_commission_sources')->where('order_id', $proof['order'])->value('earned_at'));
        $this->assertDatabaseHas('v_commission_sources', ['order_id' => $proof['order'], 'status' => 'earned']);
        $this->api('ALI-04')->getJson("/api/v1/orders/{$proof['order']}")
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.timeline.1.at', now()->toIso8601String());
        $this->control('summary')->assertJsonPath('data.collected', '174.00');

        $this->act("payments/{$proof['payment']}/confirm", ['amount_matches' => true, 'destination_ok' => true])
            ->assertConflict()
            ->assertJsonPath('code', 'already_reviewed');
    }

    #[Test]
    public function si_algo_no_coincide_se_rechaza_y_la_referencia_no_se_repite(): void
    {
        $qr = $this->proofSale('qr', 'TR-77');
        $transfer = $this->proofSale('transfer', 'TR-77', 'KIM-05');

        $this->control("payments/{$qr['payment']}")->assertJsonPath('data.reference_unique', false);
        $this->act("payments/{$qr['payment']}/confirm", ['amount_matches' => false, 'destination_ok' => true])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Si el monto o la cuenta no coinciden, rechaza el comprobante.');
        $this->act("payments/{$qr['payment']}/confirm", ['amount_matches' => true, 'destination_ok' => true])
            ->assertConflict()
            ->assertJsonPath('code', 'reference_duplicated');

        $this->act("payments/{$qr['payment']}/reject", ['reason' => '  '])
            ->assertJsonPath('message', 'Escribe el motivo.');
        $this->act("payments/{$qr['payment']}/reject", ['reason' => 'Es el mismo de la transferencia'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.reject_reason', 'Es el mismo de la transferencia');
        $this->assertDatabaseHas('orders', ['id_order' => $qr['order'], 'status' => 'pending_payment']);
        $this->confirm($transfer['payment']);
    }

    #[Test]
    public function el_dia_en_que_llego_el_dinero_no_puede_ser_futuro_ni_de_una_semana_enviada(): void
    {
        $proof = $this->proofSale();
        $confirm = fn (string $received) => $this->act("payments/{$proof['payment']}/confirm", [
            'amount_matches' => true, 'destination_ok' => true, 'received_at' => $received,
        ]);

        $confirm('2026-12-04T12:00:00-04:00')
            ->assertJsonPath('message', 'La fecha en que llegó el dinero no puede ser futura.');

        DB::table('weekly_closings')->where('week_id', $this->week()->getKey())
            ->update(['status' => 'sent', 'sent_by' => $this->member('JIM-01')->getKey(), 'sent_at' => now()]);
        $confirm('2026-12-02T20:00:00-04:00')->assertConflict()->assertJsonPath('code', 'week_closed');
        DB::table('weekly_closings')->where('week_id', $this->week()->getKey())->update(['status' => 'open']);

        // Llega en UTC y se guarda con la hora de Bolivia.
        $confirm('2026-12-03T00:00:00Z')->assertOk();
        $this->assertSame(
            '2026-12-02 20:00:00-04',
            DB::table('payments')->where('id_payment', $proof['payment'])->value('received_at'),
        );
    }

    #[Test]
    public function el_efectivo_se_confirma_al_validar_la_caja(): void
    {
        $order = $this->cashSale();
        $this->act('payments/'.$this->paymentOf($order).'/confirm', ['amount_matches' => true, 'destination_ok' => true])
            ->assertConflict()
            ->assertJsonPath('message', 'El efectivo se confirma al validar la caja del día.');

        $proof = $this->proofSale('qr', seller: 'KIM-05');
        $closing = $this->sendCash('2026-12-03');
        $this->travelTo('2026-12-03 19:00:00');
        $late = $this->cashSale('KIM-05');

        $this->control("cash-closings/{$closing}")->assertOk()->assertJsonPath('data.status', 'sent');
        $this->act("cash-closings/{$closing}/validate")->assertOk()->assertJsonPath('data.status', 'validated');
        $this->act("cash-closings/{$closing}/validate")->assertConflict()->assertJsonPath('code', 'already_reviewed');

        $this->assertDatabaseHas('payments', ['order_id' => $order, 'status' => 'confirmed']);
        $this->assertDatabaseHas('v_commission_sources', ['order_id' => $order, 'status' => 'earned']);
        // El QR se revisa aparte y el efectivo que llegó tarde va en otro cierre.
        $this->assertDatabaseHas('payments', ['id_payment' => $proof['payment'], 'status' => 'submitted']);
        $this->assertDatabaseHas('payments', ['order_id' => $late, 'status' => 'submitted']);

        $this->travelTo('2026-12-04 18:00:00');
        $next = $this->sendCash('2026-12-04');
        $this->act("cash-closings/{$next}/validate")->assertOk();
        $this->assertDatabaseHas('payments', ['order_id' => $late, 'status' => 'confirmed']);
    }

    #[Test]
    public function jime_pide_corregir_una_caja(): void
    {
        $this->cashSale();
        $closing = $this->sendCash('2026-12-03');

        $this->act("cash-closings/{$closing}/observe", ['note' => ''])
            ->assertJsonPath('message', 'Escribe qué hay que corregir.');
        $this->act("cash-closings/{$closing}/observe", ['note' => 'Falta el vuelto'])
            ->assertOk()
            ->assertJsonPath('data.status', 'observed')
            ->assertJsonPath('data.review_note', 'Falta el vuelto');

        $this->api('ISA-03')->getJson('/api/v1/cash/today')->assertJsonPath('data.status', 'observed');
        $this->assertDatabaseHas('payments', ['status' => 'submitted']);
        $this->control('cash-closings/'.$this->member('JIM-01')->getKey())->assertNotFound();
    }

    #[Test]
    public function jime_carga_el_valor_real_y_valida_la_evidencia(): void
    {
        $result = $this->submitEvidence('ALI-04', self::REGISTRY);

        $this->control("kpi-results/{$result}")
            ->assertOk()
            ->assertJsonPath('data.member.name', 'Alison')
            ->assertJsonPath('data.week_label', '1–6 dic')
            ->assertJsonPath('data.indicator.name', self::REGISTRY)
            ->assertJsonPath('data.indicator.evidence_status', 'in_review')
            ->assertJsonPath('data.indicator.actual', null)
            ->assertJsonPath('data.evidence.0.kind', 'link');
        $this->assertArrayNotHasKey('evidence', $this->control("kpi-results/{$result}")->json('data.indicator'));

        $this->act("kpi-results/{$result}/validate", ['actual' => -1])
            ->assertJsonPath('message', 'El valor real no puede ser negativo.');
        $this->act("kpi-results/{$result}/validate", ['actual' => 1.5, 'note' => 'Revisado'])
            ->assertOk()
            ->assertJsonPath('data.indicator.actual', 1.5)
            ->assertJsonPath('data.indicator.evidence_status', 'validated');
        $this->assertDatabaseHas('kpi_weekly_results', [
            'id_kpi_weekly_result' => $result, 'note' => 'Revisado',
            'loaded_by' => $this->member('JIM-01')->getKey(),
        ]);

        // Sin evidencia también se carga; observar sin nada esperando, no.
        $other = $this->resultId('ALI-04', 'Pedidos propios efectivamente pagados');
        $this->act("kpi-results/{$other}/validate", ['actual' => 4])->assertOk();
        $this->act("kpi-results/{$other}/observe", ['note' => 'Falta'])
            ->assertConflict()->assertJsonPath('code', 'already_reviewed');
    }

    #[Test]
    public function jime_pide_otra_evidencia_y_quien_la_subio_ve_por_que(): void
    {
        $result = $this->submitEvidence('ALI-04', self::REGISTRY);

        $this->act("kpi-results/{$result}/observe", ['note' => 'Falta la venta del lunes'])
            ->assertOk()
            ->assertJsonPath('data.evidence.0.status', 'observed');

        $registry = collect($this->api('ALI-04')->getJson('/api/v1/me/week')->json('data.indicators'))
            ->firstWhere('name', self::REGISTRY);
        $this->assertSame('Falta la venta del lunes', $registry['evidence'][0]['review_note']);
    }

    #[Test]
    public function jime_abre_la_semana_de_cualquier_integrante(): void
    {
        $kim = $this->member('KIM-05')->getKey();

        $response = $this->control("members/{$kim}/week?week=1")->assertOk();
        $this->assertSame(
            $this->api('KIM-05')->getJson('/api/v1/me/week?week=1')->json(),
            $response->json(),
        );
        $this->control("members/{$kim}/week?week=2")->assertNotFound();
        $this->control('members/'.$this->member('JIM-01')->getKey().'/week')->assertNotFound();
    }

    #[Test]
    public function las_entregas_quedan_a_tiempo_o_fuera_de_plazo(): void
    {
        $first = $this->submitDeliverable('SIS-06');
        $second = $this->submitDeliverable('SIS-07');

        $this->control('deliverables')->assertOk()->assertJsonCount(8, 'data');
        $this->act("deliverables/{$first}/validate", ['delivered_on' => '2026-12-04'])
            ->assertJsonPath('message', 'La fecha real no puede ser futura.');
        // Vencía el 30 de noviembre: la fecha que Jime comprueba decide.
        $this->act("deliverables/{$first}/validate", ['delivered_on' => '2026-11-30'])
            ->assertOk()
            ->assertJsonPath('data.status', 'on_time')
            ->assertJsonPath('data.member_name', 'Dana');
        $this->act("deliverables/{$first}/reject", ['note' => 'x'])
            ->assertConflict()->assertJsonPath('code', 'already_reviewed');

        $this->act("deliverables/{$second}/reject", ['note' => 'No abre el enlace'])
            ->assertOk()
            ->assertJsonPath('data.status', 'not_validated')
            ->assertJsonPath('data.review_note', 'No abre el enlace');
        $this->control('summary')->assertJsonPath('data.pending.deliverables', 0);

        // Lo que no se validó se puede volver a enviar.
        $this->assertSame($second, $this->submitDeliverable('SIS-07'));
        $this->act("deliverables/{$second}/validate", ['delivered_on' => '2026-12-02'])
            ->assertOk()
            ->assertJsonPath('data.status', 'late');
    }

    #[Test]
    public function con_el_cierre_en_direccion_la_semana_no_cambia(): void
    {
        $result = $this->submitEvidence('ALI-04', self::REGISTRY);
        $deliverable = $this->submitDeliverable();
        DB::table('weekly_closings')->where('week_id', $this->week()->getKey())
            ->update(['status' => 'sent', 'sent_by' => $this->member('JIM-01')->getKey(), 'sent_at' => now()]);

        $this->act("kpi-results/{$result}/validate", ['actual' => 1])
            ->assertConflict()
            ->assertJsonPath('code', 'week_closed')
            ->assertJsonPath('message', 'El cierre de esa semana está con Dirección. Para cambiar algo, tiene que devolverlo.');
        $this->act("kpi-results/{$result}/observe", ['note' => 'x'])->assertJsonPath('code', 'week_closed');
        $this->act("deliverables/{$deliverable}/validate", ['delivered_on' => '2026-12-02'])
            ->assertJsonPath('code', 'week_closed');
        $this->control('pending?type=deliverable')->assertJsonCount(0, 'data');

        // El equipo tampoco agrega nada mientras tanto.
        $this->api('ALI-04')->getJson('/api/v1/me/week')->assertJsonPath('data.accepts_evidence', false);
        $this->api('SIS-06')->postJson("/api/v1/me/deliverables/{$deliverable}/submission", [
            'delivered_on' => '2026-12-02', 'url' => 'a.com',
        ], ['Idempotency-Key' => '3d1b0f9e-4c55-4a53-9a38-1f0d8c1f7a11'])->assertJsonPath('code', 'week_closed');
    }
}
