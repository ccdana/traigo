<?php

namespace Tests\Feature\Api;

use App\Models\SystemDeliverable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

class DeliverableTest extends CampaignTestCase
{
    private function deliverable(string $code = 'SIS-06', int $order = 0): string
    {
        return SystemDeliverable::query()
            ->where('user_id', $this->member($code)->getKey())
            ->orderBy('sort_order')
            ->get()[$order]->getKey();
    }

    /** @param  array<string, mixed>  $data */
    private function submit(string $id, array $data, string $code = 'SIS-06'): TestResponse
    {
        return $this->api($code)->post("/api/v1/me/deliverables/{$id}/submission", $data, [
            'Accept' => 'application/json',
            'Idempotency-Key' => (string) Str::uuid(),
        ]);
    }

    private function review(string $id, bool $validated, ?string $note = null): void
    {
        DB::table('system_deliverables')->where('id_system_deliverable', $id)->update([
            'jime_validated' => $validated,
            'validated_by' => $this->member('JIM-01')->getKey(),
            'validated_at' => now(),
            'review_note' => $note,
        ]);
    }

    #[Test]
    public function enviar_con_enlace_la_deja_en_revision(): void
    {
        $id = $this->deliverable();

        $this->submit($id, ['delivered_on' => '2026-11-30', 'url' => 'traigo.bo/tienda'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_review')
            ->assertJsonPath('data.delivered_on', '2026-11-30')
            ->assertJsonPath('data.evidence_url', 'https://traigo.bo/tienda')
            ->assertJsonPath('data.member_name', 'Sistemas 1')
            ->assertJsonPath('data.review_note', null);

        // En la base sigue pendiente hasta que Jime la valide.
        $this->assertSame('pending', SystemDeliverable::query()->find($id)->result);
    }

    #[Test]
    public function con_foto_el_enlace_es_firmado(): void
    {
        $url = $this->submit($this->deliverable(), [
            'delivered_on' => '2026-12-01',
            'file' => UploadedFile::fake()->image('tienda.jpg'),
        ])->assertOk()->json('data.evidence_url');

        $this->assertStringContainsString('/entrega-', $url);
        $this->as(null)->get($url)->assertOk();
    }

    #[Test]
    public function la_fecha_no_puede_ser_futura_y_pide_evidencia(): void
    {
        $id = $this->deliverable();

        $this->submit($id, ['delivered_on' => '2026-12-04', 'url' => 'a.com'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'La fecha real no puede ser futura.');
        $this->submit($id, ['delivered_on' => '2026-12-01'])
            ->assertJsonPath('message', 'Adjunta una foto o un enlace.');
        $this->submit($id, ['delivered_on' => '01/12/2026', 'url' => 'a.com'])->assertUnprocessable();
        $this->submit($id, ['delivered_on' => '2026-12-01', 'url' => 'sin-punto'])
            ->assertJsonPath('message', 'El enlace no es válido.');
    }

    #[Test]
    public function validada_no_se_vuelve_a_enviar_y_dice_si_llego_tarde(): void
    {
        $onTime = $this->deliverable(order: 0);
        $late = $this->deliverable(order: 1);
        $this->submit($onTime, ['delivered_on' => '2026-11-30', 'url' => 'a.com']);
        $this->submit($late, ['delivered_on' => '2026-12-02', 'url' => 'a.com']);
        $this->review($onTime, true);
        $this->review($late, true);

        $statuses = collect($this->api('SIS-06')->getJson('/api/v1/me/week')->json('data.deliverables'))
            ->pluck('status', 'id');
        $this->assertSame('on_time', $statuses[$onTime]);
        $this->assertSame('late', $statuses[$late]);

        $this->submit($onTime, ['delivered_on' => '2026-11-29', 'url' => 'b.com'])
            ->assertConflict()
            ->assertJsonPath('code', 'deliverable_validated');
    }

    #[Test]
    public function si_jime_no_la_valida_se_reenvia_y_reemplaza_lo_anterior(): void
    {
        $id = $this->deliverable();
        $this->submit($id, ['delivered_on' => '2026-11-30', 'url' => 'a.com']);
        $this->review($id, false, 'No abre el enlace');

        $this->api('SIS-06')->getJson('/api/v1/me/week')
            ->assertJsonPath('data.deliverables.0.status', 'not_validated')
            ->assertJsonPath('data.deliverables.0.review_note', 'No abre el enlace');

        $this->submit($id, ['delivered_on' => '2026-12-01', 'url' => 'b.com'])
            ->assertJsonPath('data.status', 'in_review')
            ->assertJsonPath('data.review_note', null)
            ->assertJsonPath('data.evidence_url', 'https://b.com');
    }

    #[Test]
    public function solo_sistemas_y_solo_las_suyas(): void
    {
        $data = ['delivered_on' => '2026-11-30', 'url' => 'a.com'];

        $this->submit($this->deliverable('SIS-07'), $data)
            ->assertNotFound()
            ->assertJsonPath('message', 'No encontramos esa entrega entre las tuyas.');
        $this->submit($this->deliverable(), $data, 'ALI-04')->assertForbidden();
        $this->submit($this->deliverable(), $data, 'JIM-01')->assertNotFound();
    }

    #[Test]
    public function con_la_semana_cerrada_no_se_envia(): void
    {
        $this->authorizeWeek();

        $this->submit($this->deliverable(), [
            'delivered_on' => '2026-11-30',
            'file' => UploadedFile::fake()->image('a.jpg'),
        ])->assertConflict()->assertJsonPath('code', 'week_closed');

        $this->assertCount(0, Storage::disk('local')->allFiles());
    }
}
