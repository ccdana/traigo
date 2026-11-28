<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

class HomeTest extends CampaignTestCase
{
    #[Test]
    public function quien_vende_ve_el_bono_su_kpi_comision_y_ventas_de_hoy(): void
    {
        $response = $this->api('ALI-04')->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonPath('data.week', [
                'label' => '1–6 dic',
                'starts_on' => '2026-12-01',
                'ends_on' => '2026-12-06',
                'target' => '40000.00',
                'base_prize' => '1500.00',
            ])
            ->assertJsonPath('data.collected', '0.00')
            ->assertJsonPath('data.fund_share_rate', '0.20')
            ->assertJsonPath('data.kpi.score_percent', 0)
            ->assertJsonPath('data.kpi.minimum_percent', 80)
            ->assertJsonPath('data.kpi.compliance', 'pending')
            ->assertJsonPath('data.kpi.deliverables', null)
            ->assertJsonPath('data.kpi.closed_reason', null)
            ->assertJsonCount(4, 'data.kpi.indicators')
            // La meta de cantidad va al 90 % la primera semana: 30 → 27.
            ->assertJsonPath('data.kpi.indicators.0', [
                'id' => $this->resultId('ALI-04', 'Nuevos compradores contactados'),
                'name' => 'Nuevos compradores contactados',
                'target' => 27,
                'actual' => null,
                'weight' => '0.30',
                'evidence_status' => 'missing',
            ])
            ->assertJsonPath('data.month_commission', ['amount' => '0.00', 'pay_note' => null])
            ->assertJsonPath('data.today_sales', [])
            ->assertJsonPath('data.cash_today', null);

        $this->assertArrayNotHasKey('how_verified', $response->json('data.kpi.indicators.0'));
    }

    #[Test]
    public function las_ventas_de_hoy_y_lo_cobrado_salen_de_los_pedidos(): void
    {
        $this->travelTo('2026-12-02 18:00:00');
        $yesterday = $this->sell($this->sale())->json('data.id');
        $this->travelTo('2026-12-03 09:00:00');
        $first = $this->sell($this->sale())->json('data.id');
        $this->travel(1)->hours();
        $cancelled = $this->sell($this->sale())->json('data.id');
        $this->api('ALI-04')->postJson("/api/v1/orders/{$cancelled}/cancel")->assertOk();
        $this->sell($this->sale(), 'KIM-05');
        $this->travel(1)->hours();
        $this->confirmPayment($first);
        $this->confirmPayment($yesterday);

        $this->api('ALI-04')->getJson('/api/v1/home')
            ->assertJsonCount(1, 'data.today_sales')
            ->assertJsonPath('data.today_sales.0.id', $first)
            ->assertJsonPath('data.today_sales.0.items_label', 'Tomatodos de Messi × 2')
            ->assertJsonPath('data.today_sales.0.payment_method', 'qr')
            ->assertJsonPath('data.today_sales.0.total', '174.00')
            ->assertJsonPath('data.collected', '348.00');
    }

    #[Test]
    public function la_comision_del_mes_no_tiene_monto_si_falta_algun_porcentaje(): void
    {
        $this->confirmPayment($this->sell($this->sale())->json('data.id'));

        $this->api('ALI-04')->getJson('/api/v1/home')->assertJsonPath('data.month_commission.amount', null);

        // Con el % definido, ya suma (y la nota de pago sale de los ajustes).
        DB::table('commissions')->update(['rate_pct' => 5, 'amount_bs' => DB::raw('round(base_bs * 5 / 100, 2)')]);
        DB::table('settings')->where('key', 'commission_pay_note')->update(['value' => json_encode('El día 5')]);

        $this->api('ALI-04')->getJson('/api/v1/home')
            ->assertJsonPath('data.month_commission', ['amount' => '8.70', 'pay_note' => 'El día 5']);

        // El mes siguiente empieza de cero.
        $this->travelTo('2027-01-02 10:00:00');
        $this->api('ALI-04')->getJson('/api/v1/home')->assertJsonPath('data.month_commission.amount', '0.00');
    }

    #[Test]
    public function almacen_ve_la_caja_de_hoy_y_los_dias_sin_cerrar(): void
    {
        $this->sell($this->sale(with: ['payment_method' => 'cash']))->assertCreated();
        $this->sell($this->sale())->assertCreated();

        $this->api('ISA-03')->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonPath('data.month_commission.amount', '0.00')
            ->assertJsonPath('data.today_sales', [])
            ->assertJsonPath('data.cash_today', [
                'collected' => '174.00',
                'paid_orders' => 1,
                'pending_orders' => 1,
                'status' => 'open',
                // El 1 y el 2 de diciembre.
                'pending_days' => 2,
            ]);

        DB::table('cash_closings')->insert([
            'business_date' => '2026-12-01', 'status' => 'sent', 'sent_at' => now(),
            'closed_by' => $this->member('ISA-03')->getKey(),
        ]);

        $this->api('ISA-03')->getJson('/api/v1/home')->assertJsonPath('data.cash_today.pending_days', 1);
    }

    #[Test]
    public function antes_de_la_campaña_no_hay_dias_de_caja_pendientes(): void
    {
        $this->travelTo('2026-11-25 10:00:00');

        $this->api('ISA-03')->getJson('/api/v1/home')
            ->assertJsonPath('data.week.label', '1–6 dic')
            ->assertJsonPath('data.cash_today.pending_days', 0);
    }

    #[Test]
    public function sistemas_ve_sus_entregas_la_primera_semana(): void
    {
        $this->api('SIS-06')->getJson('/api/v1/home')
            ->assertJsonPath('data.fund_share_rate', '0.10')
            ->assertJsonPath('data.kpi.deliverables', ['approved' => 0, 'total' => 4]);

        $this->travelTo('2026-12-09 10:00:00');

        $this->api('SIS-06')->getJson('/api/v1/home')
            ->assertJsonPath('data.week.label', '7–13 dic')
            ->assertJsonPath('data.kpi.deliverables', null);
    }

    #[Test]
    public function despues_de_la_campaña_queda_la_ultima_semana(): void
    {
        $this->travelTo('2027-02-01 10:00:00');

        $this->api('AIL-02')->getJson('/api/v1/home')->assertJsonPath('data.week.label', '11–14 ene');
    }

    #[Test]
    public function jime_y_direccion_no_tienen_inicio_de_equipo(): void
    {
        foreach (['JIM-01', 'DIR-08'] as $code) {
            foreach (['/api/v1/home', '/api/v1/team/week', '/api/v1/me/week'] as $path) {
                $this->api($code)->getJson($path)
                    ->assertNotFound()
                    ->assertJsonPath('message', 'Esto es para el equipo.');
            }
        }
    }
}
