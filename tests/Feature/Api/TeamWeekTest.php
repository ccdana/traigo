<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

class TeamWeekTest extends CampaignTestCase
{
    #[Test]
    public function muestra_los_escalones_mi_parte_y_la_campaña(): void
    {
        $this->api('ALI-04')->getJson('/api/v1/team/week')
            ->assertOk()
            ->assertJsonPath('data.week.label', '1–6 dic')
            ->assertJsonPath('data.collected', '0.00')
            ->assertJsonPath('data.tiers', [
                ['tier' => 'partial', 'threshold_percent' => 90, 'sales_needed' => '36000.00', 'fund' => '750.00', 'my_share' => '150.00'],
                ['tier' => 'complete', 'threshold_percent' => 100, 'sales_needed' => '40000.00', 'fund' => '1500.00', 'my_share' => '300.00'],
                ['tier' => 'superior', 'threshold_percent' => 115, 'sales_needed' => '46000.00', 'fund' => '1875.00', 'my_share' => '375.00'],
            ])
            ->assertJsonPath('data.me', [
                'fund_share_rate' => '0.20',
                'minimum_percent' => 80,
                'partial_score_percent' => 0,
                'compliance' => 'pending',
            ])
            ->assertJsonCount(7, 'data.weeks')
            ->assertJsonPath('data.weeks.0.number', 1)
            ->assertJsonPath('data.weeks.0.is_current', true)
            ->assertJsonPath('data.weeks.1', [
                'number' => 2,
                'label' => '7–13 dic',
                'starts_on' => '2026-12-07',
                'ends_on' => '2026-12-13',
                'target' => '60000.00',
                'base_prize' => '2500.00',
                'is_current' => false,
            ]);
    }

    #[Test]
    public function la_parte_de_cada_quien_es_la_suya(): void
    {
        $this->travelTo('2026-12-16 10:00:00');

        $this->api('SIS-07')->getJson('/api/v1/team/week')
            ->assertJsonPath('data.week.label', '14–20 dic')
            // 85 000 y premio base 3 500; Sistemas tiene el 10 %.
            ->assertJsonPath('data.tiers.0.sales_needed', '76500.00')
            ->assertJsonPath('data.tiers.0.my_share', '175.00')
            ->assertJsonPath('data.tiers.2.fund', '4375.00')
            ->assertJsonPath('data.tiers.2.my_share', '437.50')
            ->assertJsonPath('data.weeks.2.is_current', true);
    }

    #[Test]
    public function el_avance_se_redondea_hacia_abajo(): void
    {
        $this->loadActuals('ALI-04', 0.999);

        $this->api('ALI-04')->getJson('/api/v1/team/week')
            ->assertJsonPath('data.me.partial_score_percent', 99)
            ->assertJsonPath('data.me.compliance', 'passes');
    }

    #[Test]
    public function cambiar_el_perfil_no_modifica_el_bono_de_una_semana_asignada(): void
    {
        DB::table('team_profiles')->where('user_id', $this->member('ALI-04')->getKey())
            ->update(['bonus_share_pct' => 15, 'min_kpi_pct' => 90]);

        $this->api('ALI-04')->getJson('/api/v1/team/week')
            ->assertOk()
            ->assertJsonPath('data.me.fund_share_rate', '0.20')
            ->assertJsonPath('data.me.minimum_percent', 80);
    }
}
