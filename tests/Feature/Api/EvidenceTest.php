<?php

namespace Tests\Feature\Api;

use App\Models\KpiEvidence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

class EvidenceTest extends CampaignTestCase
{
    private const REGISTRY = 'Registro semanal de ventas';

    /** @param  array<string, mixed>  $data */
    private function send(string $result, array $data, string $code = 'ALI-04', ?string $key = null): TestResponse
    {
        return $this->api($code)->post("/api/v1/me/kpi-results/{$result}/evidence", $data, [
            'Accept' => 'application/json',
            'Idempotency-Key' => $key ?? (string) Str::uuid(),
        ]);
    }

    /** Jime revisa la evidencia más reciente. */
    private function review(string $status, ?string $note = null): void
    {
        DB::table('kpi_evidence')->update([
            'status' => $status,
            'review_note' => $note,
            'reviewed_by' => $this->member('JIM-01')->getKey(),
            'reviewed_at' => now(),
        ]);
    }

    #[Test]
    public function una_foto_queda_en_revision_y_se_abre_con_su_enlace(): void
    {
        $result = $this->resultId('ALI-04', self::REGISTRY);

        $response = $this->send($result, [
            'kind' => 'photo',
            'file' => UploadedFile::fake()->image('registro.png'),
            'note' => '  Incluye las ventas del lunes ',
        ])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'photo')
            ->assertJsonPath('data.status', 'in_review')
            ->assertJsonPath('data.note', 'Incluye las ventas del lunes')
            ->assertJsonPath('data.order_id', null)
            ->assertJsonPath('data.submitted_at', now()->toIso8601String())
            ->assertJsonPath('data.review_note', null);

        $url = $response->json('data.url');
        $this->assertStringContainsString('/evidencia-', $url);
        $this->as(null)->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');

        $week = $this->api('ALI-04')->getJson('/api/v1/me/week')->json('data.indicators');
        $registry = collect($week)->firstWhere('name', self::REGISTRY);
        $this->assertSame('in_review', $registry['evidence_status']);
        $this->assertSame($response->json('data.id'), $registry['evidence'][0]['id']);
    }

    #[Test]
    public function un_enlace_se_completa_y_uno_malo_no_pasa(): void
    {
        $result = $this->resultId('ALI-04', self::REGISTRY);

        $this->send($result, ['kind' => 'link', 'url' => ' drive.google.com/informe '])
            ->assertCreated()
            ->assertJsonPath('data.url', 'https://drive.google.com/informe');

        foreach (['informe', 'ftp://servidor.com/a', 'https://.com', 'mi informe.com'] as $bad) {
            $this->send($result, ['kind' => 'link', 'url' => $bad])
                ->assertUnprocessable()
                ->assertJsonPath('message', 'El enlace no es válido.');
        }
    }

    #[Test]
    public function quien_vende_adjunta_un_pedido_propio_vigente(): void
    {
        $result = $this->resultId('ALI-04', 'Pedidos propios efectivamente pagados');
        $mine = $this->sell($this->sale())->json('data');
        $theirs = $this->sell($this->sale(), 'KIM-05')->json('data.id');

        $this->send($result, ['kind' => 'order', 'order_id' => $mine['id']])
            ->assertCreated()
            ->assertJsonPath('data.order_number', $mine['number'])
            ->assertJsonPath('data.url', null);

        $this->send($result, ['kind' => 'order', 'order_id' => $theirs])->assertNotFound();

        $this->api('ALI-04')->postJson("/api/v1/orders/{$mine['id']}/cancel")->assertOk();
        $this->send($result, ['kind' => 'order', 'order_id' => $mine['id']])
            ->assertJsonPath('message', 'Ese pedido está anulado.');

        $this->send($this->resultId('AIL-02', 'LIVES realizados'), ['kind' => 'order', 'order_id' => $theirs], 'AIL-02')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Solo quienes venden adjuntan pedidos.');
    }

    #[Test]
    public function revisa_lo_que_falta_y_el_tipo_de_archivo(): void
    {
        $result = $this->resultId('ALI-04', self::REGISTRY);

        $this->send($result, ['kind' => 'photo'])->assertJsonPath('message', 'Falta la foto.');
        $this->send($result, ['kind' => 'photo', 'file' => UploadedFile::fake()->create('a.pdf', 20, 'application/pdf')])
            ->assertJsonPath('message', 'Solo fotos JPG o PNG.');
        $this->send($result, ['kind' => 'link'])->assertJsonPath('message', 'Falta el enlace.');
        $this->send($result, ['kind' => 'order'])->assertJsonPath('message', 'Elige el pedido.');
        $this->send($result, ['kind' => 'video'])->assertUnprocessable();
        $this->send($result, ['kind' => 'link', 'url' => 'a.com', 'note' => str_repeat('x', 501)])
            ->assertJsonValidationErrors('note');
        $this->api('ALI-04')->postJson("/api/v1/me/kpi-results/{$result}/evidence", ['kind' => 'link', 'url' => 'a.com'])
            ->assertJsonPath('message', 'Falta la clave del envío.');

        $this->assertDatabaseCount('kpi_evidence', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    #[Test]
    public function solo_los_indicadores_propios_de_una_semana_empezada(): void
    {
        $this->send($this->resultId('KIM-05', 'Cotizaciones enviadas'), ['kind' => 'link', 'url' => 'a.com'])
            ->assertNotFound()
            ->assertJsonPath('message', 'No encontramos ese indicador entre los tuyos.');

        $this->send($this->resultId('ALI-04', self::REGISTRY, 2), ['kind' => 'link', 'url' => 'a.com'])
            ->assertNotFound()
            ->assertJsonPath('message', 'Esa semana todavía no empieza.');

        $this->send($this->resultId('ALI-04', self::REGISTRY), ['kind' => 'link', 'url' => 'a.com'], 'JIM-01')
            ->assertNotFound();
    }

    #[Test]
    public function con_lo_validado_no_se_agrega_y_lo_observado_se_vuelve_a_subir(): void
    {
        $result = $this->resultId('ALI-04', self::REGISTRY);
        $this->send($result, ['kind' => 'link', 'url' => 'a.com'])->assertCreated();

        $this->review('validated');
        $this->send($result, ['kind' => 'link', 'url' => 'b.com'])
            ->assertConflict()
            ->assertJsonPath('code', 'evidence_validated');

        $this->review('observed', 'Falta una venta');
        $this->travel(1)->minutes();
        $this->send($result, ['kind' => 'link', 'url' => 'b.com'])->assertCreated();

        $registry = collect($this->api('ALI-04')->getJson('/api/v1/me/week')->json('data.indicators'))
            ->firstWhere('name', self::REGISTRY);
        $this->assertSame(['in_review', 'observed'], array_column($registry['evidence'], 'status'));
        $this->assertSame('Falta una venta', $registry['evidence'][1]['review_note']);
    }

    #[Test]
    public function con_la_semana_cerrada_no_se_sube_nada(): void
    {
        $this->closeByHand('ALI-04', 'Cerrada');

        $this->send($this->resultId('ALI-04', self::REGISTRY), [
            'kind' => 'photo',
            'file' => UploadedFile::fake()->image('a.jpg'),
        ])->assertConflict()->assertJsonPath('code', 'week_closed');

        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    #[Test]
    public function un_reintento_no_duplica_la_evidencia(): void
    {
        $result = $this->resultId('ALI-04', self::REGISTRY);
        $key = (string) Str::uuid();
        $data = ['kind' => 'link', 'url' => 'a.com'];

        $first = $this->send($result, $data, key: $key)->assertCreated()->json('data.id');
        $this->send($result, $data, key: $key)->assertHeader('Idempotent-Replayed', 'true');
        Cache::flush();
        $this->send($result, $data, key: $key)->assertCreated()->assertJsonPath('data.id', $first);

        $this->assertSame(1, KpiEvidence::query()->count());
    }
}
