<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

/** Cumplimiento, cierre semanal y bono. La semana 1 va del 1 al 6 de diciembre. */
class WeeklyClosingTest extends ControlTestCase
{
    /** Un cobro confirmado grande de la web, para llegar a un escalón. */
    private function collect(string $amount, string $received = '2026-12-03 11:00:00'): string
    {
        $id = (string) Str::uuid7();
        DB::table('orders')->insert([
            'id_order' => $id, 'origin' => 'web', 'status' => 'delivered', 'customer_name' => 'Mayorista web',
            'paid_at' => $received, 'created_at' => $received,
        ]);
        DB::table('payments')->insert([
            'order_id' => $id, 'method' => 'transfer', 'status' => 'confirmed', 'amount_bs' => $amount,
            'proof_url' => 'comprobantes/web.jpg', 'submitted_at' => $received, 'reviewed_at' => $received,
            'received_at' => $received, 'created_at' => $received,
        ]);

        return $id;
    }

    /** Lunes 7: la semana 1 con todo cargado, salvo lo que se indique. */
    private function readyToClose(array $except = [], bool $deliverSystems = true): void
    {
        $this->collect('41600');
        $this->travelTo('2026-12-07 10:00:00');
        $this->loadTeam(except: $except);
        if ($deliverSystems) {
            $this->deliverAll('SIS-06');
            $this->deliverAll('SIS-07');
        }
    }

    #[Test]
    public function el_panel_abre_la_semana_que_falta_cerrar(): void
    {
        $this->control('weekly-closing')
            ->assertOk()
            ->assertJsonPath('data.week.number', 1)
            ->assertJsonPath('data.current_week', 1)
            ->assertJsonPath('data.sendable_from', '2026-12-07')
            ->assertJsonPath('data.status', 'open');

        // El domingo todavía no se puede enviar.
        $this->travelTo('2026-12-06 20:00:00');
        $this->sendWeek()
            ->assertConflict()
            ->assertJsonPath('code', 'week_not_ended')
            ->assertJsonPath('message', 'El cierre se envía cuando termina la semana (desde el 7 dic).');

        // Ya en la semana 2, sigue abriendo la 1 que falta cerrar.
        $this->travelTo('2026-12-09 10:00:00');
        $this->control('weekly-closing')->assertJsonPath('data.week.number', 1)->assertJsonPath('data.current_week', 2);
        $this->control('compliance')->assertJsonPath('meta.week.number', 1)->assertJsonPath('meta.current_week', 2);
        $this->control('bonus')->assertJsonPath('data.week.number', 1);
        $this->control('summary')->assertJsonPath('data.week.label', '7–13 dic');
        $this->control('compliance?week=2')->assertJsonPath('meta.week.number', 2);
        $this->control('weekly-closing?week=3')->assertNotFound();
    }

    #[Test]
    public function cumplimiento_muestra_lo_cargado_y_las_entregas_de_la_primera_semana(): void
    {
        $this->loadActuals('ALI-04');
        $this->loadActuals('KIM-05', 0.5);

        $response = $this->control('compliance')->assertOk()->assertJsonCount(6, 'data');
        $rows = collect($response->json('data'))->keyBy('name');

        $this->assertSame([100, 'passes', 4, 4], [
            $rows['Alison']['score_percent'], $rows['Alison']['compliance'],
            $rows['Alison']['indicators_loaded'], $rows['Alison']['indicators_total'],
        ]);
        $this->assertSame(['fails', null], [$rows['Kim']['compliance'], $rows['Kim']['deliverables_total']]);
        $this->assertSame([null, 'pending', 0, 4], [
            $rows['Sistemas 1']['score_percent'], $rows['Sistemas 1']['compliance'],
            $rows['Sistemas 1']['deliverables_on_time'], $rows['Sistemas 1']['deliverables_total'],
        ]);

        $this->travelTo('2026-12-09 10:00:00');
        $this->control('compliance?week=2')->assertJsonPath('data.4.deliverables_total', null);
    }

    #[Test]
    public function no_se_envia_con_cobros_o_evidencia_sin_revisar(): void
    {
        $proof = $this->proofSale();
        $this->cashSale('KIM-05');
        $evidence = $this->submitEvidence('ALI-04', 'Registro semanal de ventas');
        $this->readyToClose(except: []);

        $this->control('weekly-closing')
            ->assertJsonPath('data.checks.payments_reconciled', [
                'ok' => false, 'detail' => 'Faltan 1 comprobante por revisar y 1 en efectivo sin caja validada',
            ])
            ->assertJsonPath('data.checks.kpi_evidence', [
                'ok' => false, 'detail' => 'Falta revisar la evidencia de Alison',
            ])
            ->assertJsonPath('data.checks.duplicates_cleared.ok', false)
            ->assertJsonPath('data.checks.direction_approval', ['ok' => false, 'detail' => 'Falta su aprobación']);
        $this->sendWeek()
            ->assertUnprocessable()
            ->assertJsonPath('code', 'checks_failed')
            ->assertJsonPath('message', 'Faltan 1 comprobante por revisar y 1 en efectivo sin caja validada');

        $this->confirm($proof['payment']);
        $closing = $this->sendCash('2026-12-03');
        $this->act("cash-closings/{$closing}/validate")->assertOk();
        $this->sendWeek()->assertJsonPath('message', 'Falta revisar la evidencia de Alison');
        $this->act("kpi-results/{$evidence}/validate", ['actual' => 1])->assertOk();

        $this->control('weekly-closing')
            ->assertJsonPath('data.checks.payments_reconciled.detail', '3 de 3 pagos confirmados')
            ->assertJsonPath('data.checks.kpi_evidence.detail', '6 de 6 personas sin evidencia por revisar');
        $this->sendWeek(duplicates: false)->assertJsonPath('message', 'Confirma que no quedan duplicados.');
        $this->sendWeek()->assertOk()->assertJsonPath('data.status', 'sent');
    }

    #[Test]
    public function quien_sigue_pendiente_se_resuelve_o_se_cierra_con_motivo(): void
    {
        $this->readyToClose(except: ['KIM-05'], deliverSystems: false);
        $this->deliverAll('SIS-06');

        $this->control('weekly-closing')->assertJsonPath('data.pending_members', [
            ['id' => $this->member('KIM-05')->getKey(), 'name' => 'Kim', 'pending_reason' => 'Faltan 6 valores reales'],
            ['id' => $this->member('SIS-07')->getKey(), 'name' => 'Sistemas 2', 'pending_reason' => '0 de 4 entregas validadas'],
        ]);
        $this->sendWeek()
            ->assertConflict()
            ->assertJsonPath('code', 'pending_members')
            ->assertJsonPath('message', 'Kim y Sistemas 2 siguen pendientes.');
        $this->sendWeek(closePending: ['ALI-04' => 'x'])
            ->assertUnprocessable()->assertJsonPath('code', 'not_pending');
        $this->sendWeek(closePending: ['KIM-05' => ' ', 'SIS-07' => 'Sin entregas'])
            ->assertJsonPath('message', 'Escribe el motivo de cada cierre.');
        $this->assertDatabaseCount('weekly_member_results', 0);

        $this->sendWeek(closePending: ['KIM-05' => 'No entregó sus cotizaciones', 'SIS-07' => 'Sin entregas'])
            ->assertOk()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.pending_members', [])
            ->assertJsonPath('data.checks.duplicates_cleared.ok', true);
        $this->sendWeek()->assertConflict()->assertJsonPath('code', 'closing_already_sent');

        $this->assertDatabaseHas('weekly_member_results', [
            'user_id' => $this->member('KIM-05')->getKey(), 'result' => 'fails', 'amount_bs' => 0,
            'closed_by' => $this->member('JIM-01')->getKey(), 'close_reason' => 'No entregó sus cotizaciones',
        ]);
        $this->control('compliance?week=1')->assertJsonPath('data.3.closed_reason', 'No entregó sus cotizaciones');
        $this->api('KIM-05')->getJson('/api/v1/me/week?week=1')
            ->assertJsonPath('data.compliance', 'fails')
            ->assertJsonPath('data.accepts_evidence', false);
    }

    #[Test]
    public function el_bono_es_la_foto_enviada_y_se_paga_con_la_autorizacion(): void
    {
        $order = $this->collect('100', '2026-12-04 11:00:00');
        $this->readyToClose(except: ['SIS-07']);
        $this->control('bonus')
            ->assertOk()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.members.5.compliance', 'pending')
            ->assertJsonPath('data.members.5.amount', null)
            ->assertJsonPath('data.members.5.share_amount', '150.00');

        $this->sendWeek(closePending: ['SIS-07' => 'Sin sus valores'])->assertOk();
        // Una devolución después no cambia lo que Dirección va a autorizar.
        $this->act("orders/{$order}/return", ['reason' => 'Devuelto'])->assertNoContent();

        $this->control('bonus')
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.collected', '41700.00')
            ->assertJsonPath('data.achievement_percent', 104)
            ->assertJsonPath('data.tier', 'complete')
            ->assertJsonPath('data.release_factor', '1.00')
            ->assertJsonPath('data.fund', '1500.00')
            ->assertJsonPath('data.payable', '1350.00')
            ->assertJsonPath('data.unpaid', '150.00')
            ->assertJsonPath('data.members.0', [
                'id' => $this->member('AIL-02')->getKey(), 'name' => 'Ailén', 'fund_share_rate' => '0.20',
                'compliance' => 'passes', 'share_amount' => '300.00', 'amount' => '300.00',
                'paid_at' => null, 'closed_reason' => null,
            ])
            ->assertJsonPath('data.members.5.amount', '0.00')
            ->assertJsonPath('data.members.5.closed_reason', 'Sin sus valores');

        $ailen = $this->member('AIL-02')->getKey();
        $this->act("bonus/1/members/{$ailen}/paid")
            ->assertConflict()->assertJsonPath('message', 'Todavía no lo autorizó Dirección.');

        $this->directionAuthorizes();
        $this->control('weekly-closing?week=1')
            ->assertJsonPath('data.checks.direction_approval', ['ok' => true, 'detail' => 'Autorizado el 7 dic']);
        $this->act('bonus/1/members/'.$this->member('SIS-07')->getKey().'/paid')
            ->assertJsonPath('message', 'Ese pago no está pendiente.');
        $this->act("bonus/1/members/{$ailen}/paid")
            ->assertOk()->assertJsonPath('data.members.0.paid_at', now()->toIso8601String());
        $this->act("bonus/1/members/{$ailen}/paid")->assertConflict();

        $this->travelTo('2026-12-09 10:00:00');
        $this->control('weekly-closing')->assertJsonPath('data.week.number', 2);
        // La 1 todavía tiene pagos por marcar.
        $this->control('bonus')->assertJsonPath('data.week.number', 1);
        foreach (['ISA-03', 'ALI-04', 'KIM-05', 'SIS-06'] as $code) {
            $this->act('bonus/1/members/'.$this->member($code)->getKey().'/paid')->assertOk();
        }
        $this->control('bonus?week=1')->assertJsonPath('data.status', 'paid');
        $this->control('bonus')->assertJsonPath('data.week.number', 2);
    }

    #[Test]
    public function lo_devuelto_por_direccion_se_corrige_y_se_vuelve_a_enviar(): void
    {
        $this->readyToClose(except: ['SIS-07']);
        $this->sendWeek(closePending: ['SIS-07' => 'Sin sus valores'])->assertOk();
        $this->directionReturns('Carga los valores de Sistemas 2');

        $this->control('weekly-closing')
            ->assertJsonPath('data.status', 'returned')
            ->assertJsonPath('data.return_reason', 'Carga los valores de Sistemas 2')
            ->assertJsonPath('data.pending_members.0.name', 'Sistemas 2');

        $this->loadActuals('SIS-07');
        $this->sendWeek()->assertOk()->assertJsonPath('data.status', 'sent')->assertJsonPath('data.return_reason', null);
        $this->assertSame(6, DB::table('weekly_member_results')->where('result', 'passes')->count());
    }

    #[Test]
    public function direccion_solo_mira(): void
    {
        $this->sendWeek()->assertConflict();
        $this->act('weekly-closing/1/send', ['duplicates_cleared' => true, 'close_pending' => []], 'DIR-08')
            ->assertForbidden();
        $this->act('bonus/1/members/'.$this->member('AIL-02')->getKey().'/paid', [], 'DIR-08')->assertForbidden();
    }
}
