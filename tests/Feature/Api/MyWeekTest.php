<?php

namespace Tests\Feature\Api;

use PHPUnit\Framework\Attributes\Test;

class MyWeekTest extends CampaignTestCase
{
    #[Test]
    public function mi_semana_trae_mis_indicadores_con_su_evidencia(): void
    {
        $this->api('ALI-04')->getJson('/api/v1/me/week')
            ->assertOk()
            ->assertJsonPath('data.week.number', 1)
            ->assertJsonPath('data.week.label', '1–6 dic')
            ->assertJsonPath('data.count_target_factor', '0.90')
            ->assertJsonPath('data.score_percent', null)
            ->assertJsonPath('data.partial_score_percent', 0)
            ->assertJsonPath('data.minimum_percent', 80)
            ->assertJsonPath('data.compliance', 'pending')
            ->assertJsonCount(4, 'data.indicators')
            ->assertJsonPath('data.indicators.0.unit', 'count')
            ->assertJsonPath('data.indicators.0.how_verified', 'Contactos prospectados, no duplicados')
            ->assertJsonPath('data.indicators.0.evidence_status', 'missing')
            ->assertJsonPath('data.indicators.0.evidence', [])
            ->assertJsonPath('data.deliverables', [])
            ->assertJsonPath('data.accepts_evidence', true)
            ->assertJsonPath('data.closed_reason', null);
    }

    #[Test]
    public function con_todo_cargado_hay_puntaje_y_resultado(): void
    {
        $this->loadActuals('ALI-04');

        $this->api('ALI-04')->getJson('/api/v1/me/week')
            ->assertJsonPath('data.score_percent', 100)
            ->assertJsonPath('data.compliance', 'passes')
            ->assertJsonPath('data.indicators.0.actual', 27);
    }

    #[Test]
    public function sistemas_ve_sus_cuatro_entregas(): void
    {
        $this->api('SIS-06')->getJson('/api/v1/me/week')
            ->assertJsonCount(4, 'data.deliverables')
            ->assertJsonPath('data.deliverables.0.status', 'pending')
            ->assertJsonPath('data.deliverables.0.member_name', 'Sistemas 1')
            ->assertJsonPath('data.deliverables.0.due_on', '2026-11-30')
            ->assertJsonPath('data.deliverables.0.evidence_url', null);
    }

    #[Test]
    public function se_revisan_las_semanas_pasadas_pero_no_las_que_no_empiezan(): void
    {
        $this->api('ALI-04')->getJson('/api/v1/me/week?week=1')->assertOk();
        $this->api('ALI-04')->getJson('/api/v1/me/week?week=2')
            ->assertNotFound()
            ->assertJsonPath('message', 'Esa semana todavía no empieza.');
        $this->api('ALI-04')->getJson('/api/v1/me/week?week=0')->assertUnprocessable();

        $this->travelTo('2026-12-08 10:00:00');
        $this->api('ALI-04')->getJson('/api/v1/me/week')->assertJsonPath('data.week.number', 2);
        $this->api('ALI-04')->getJson('/api/v1/me/week?week=1')
            ->assertJsonPath('data.week.number', 1)
            ->assertJsonPath('data.accepts_evidence', true);
    }

    #[Test]
    public function si_jime_cerro_mi_semana_o_ya_se_autorizo_no_admite_mas(): void
    {
        $this->closeByHand('ALI-04', 'No envió su informe');

        $this->api('ALI-04')->getJson('/api/v1/me/week')
            ->assertJsonPath('data.accepts_evidence', false)
            ->assertJsonPath('data.compliance', 'fails')
            ->assertJsonPath('data.closed_reason', 'No envió su informe');
        $this->api('KIM-05')->getJson('/api/v1/me/week')->assertJsonPath('data.accepts_evidence', true);

        $this->travelTo('2026-12-08 10:00:00');
        $this->authorizeWeek(2);
        $this->api('KIM-05')->getJson('/api/v1/me/week')->assertJsonPath('data.accepts_evidence', false);
    }
}
