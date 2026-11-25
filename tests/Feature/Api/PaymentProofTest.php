<?php

namespace Tests\Feature\Api;

use App\Models\Payment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

class PaymentProofTest extends SalesTestCase
{
    private string $disk;

    protected function setUp(): void
    {
        parent::setUp();

        // El disco real (con sus enlaces firmados) en una carpeta de pruebas.
        $this->disk = storage_path('framework/testing/comprobantes-'.Str::random(8));
        config(['filesystems.disks.local.root' => $this->disk]);
        Storage::forgetDisk('local');
        $this->freezeSecond();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->disk);

        parent::tearDown();
    }

    private function upload(
        string $order,
        ?UploadedFile $file = null,
        ?string $reference = null,
        string $seller = 'ALI-04',
        ?string $key = null,
    ): TestResponse {
        return $this->api($seller)->post("/api/v1/orders/{$order}/payment-proof", array_filter([
            'file' => $file ?? UploadedFile::fake()->image('foto.jpg', 600, 900),
            'reference' => $reference,
        ]), ['Accept' => 'application/json', 'Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function newOrder(string $method = 'qr', string $seller = 'ALI-04'): string
    {
        return $this->sell($this->sale(with: ['payment_method' => $method]), $seller)->assertCreated()->json('data.id');
    }

    /** Descarga el enlace como lo haría la app: sin sesión. */
    private function download(string $url): TestResponse
    {
        return $this->as(null)->get($url);
    }

    #[Test]
    public function subir_el_comprobante_pone_el_pedido_en_revision(): void
    {
        $order = $this->newOrder();

        $response = $this->upload($order, reference: '  0045-9001  ')
            ->assertOk()
            ->assertJsonPath('data.status', 'payment_review')
            ->assertJsonPath('data.payment.status', 'submitted')
            ->assertJsonPath('data.payment.reference', '0045-9001')
            ->assertJsonPath('data.payment.submitted_at', now()->toIso8601String())
            ->assertJsonPath('data.can_upload_proof', true);

        $url = $response->json('data.payment.proof_url');
        $this->assertStringContainsString('/comprobante-N-', $url);
        $this->assertStringContainsString('signature=', $url);

        $file = $this->download($url)->assertOk();
        $this->assertSame('image/jpeg', $file->headers->get('Content-Type'));

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('order_status_history', ['order_id' => $order, 'to_status' => 'payment_review']);
    }

    #[Test]
    public function el_enlace_no_sirve_modificado_ni_vencido(): void
    {
        $url = $this->upload($this->newOrder())->json('data.payment.proof_url');

        $this->download(preg_replace('/signature=\w{6}/', 'signature=000000', $url))->assertForbidden();
        $this->download(strtok($url, '?'))->assertForbidden();

        $this->travel(61)->minutes();
        $this->download($url)->assertForbidden();
    }

    #[Test]
    public function subir_otro_reemplaza_al_anterior(): void
    {
        $order = $this->newOrder();
        $first = $this->upload($order, reference: 'A-1')->json('data.payment.proof_url');

        $second = $this->upload($order, UploadedFile::fake()->create('c.pdf', 200, 'application/pdf'), 'A-1')
            ->assertOk()
            ->assertJsonPath('data.status', 'payment_review')
            ->json('data.payment.proof_url');

        $this->assertNotSame($first, $second);
        $this->assertStringContainsString('.pdf', $second);
        $this->assertDatabaseCount('payments', 1);
    }

    #[Test]
    public function si_jime_lo_rechazo_se_sube_como_un_cobro_nuevo(): void
    {
        $order = $this->newOrder();
        $this->upload($order, reference: 'B-1')->assertOk();
        Payment::query()->sole()->forceFill(['status' => 'rejected', 'reject_reason' => 'No se lee'])->save();
        \DB::table('orders')->where('id_order', $order)->update(['status' => 'pending_payment']);

        $this->api('ALI-04')->getJson("/api/v1/orders/{$order}")
            ->assertJsonPath('data.payment.status', 'rejected')
            ->assertJsonPath('data.payment.reject_reason', 'No se lee');

        $this->travel(1)->minutes();
        $this->upload($order, reference: 'B-1')
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'submitted')
            ->assertJsonPath('data.payment.reject_reason', null);

        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseHas('payments', ['status' => 'rejected', 'reject_reason' => 'No se lee']);
    }

    #[Test]
    public function una_referencia_no_se_usa_en_dos_cobros(): void
    {
        $first = $this->newOrder();
        $second = $this->newOrder(seller: 'KIM-05');
        $this->upload($first, reference: '0045-8812')->assertOk();

        $this->upload($second, reference: '0045-8812', seller: 'KIM-05')
            ->assertConflict()
            ->assertJsonPath('code', 'reference_duplicated');

        $this->assertDatabaseHas('orders', ['id_order' => $second, 'status' => 'pending_payment']);
        $this->assertCount(1, Storage::disk('local')->allFiles());

        // Al cancelar el primero, la referencia queda libre.
        $this->api('ALI-04')->postJson("/api/v1/orders/{$first}/cancel")->assertJsonPath('data.payment', null);
        $this->assertDatabaseHas('payments', ['order_id' => $first, 'status' => 'rejected']);
        $this->upload($second, reference: '0045-8812', seller: 'KIM-05')->assertOk();
    }

    #[Test]
    public function solo_fotos_o_pdf_de_hasta_5_mb(): void
    {
        $order = $this->newOrder();

        $this->upload($order, UploadedFile::fake()->create('nota.txt', 10, 'text/plain'))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Solo fotos JPG o PNG, o un PDF.');
        $this->upload($order, UploadedFile::fake()->create('grande.jpg', 5121, 'image/jpeg'))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'El archivo no puede pesar más de 5 MB.');
        $this->api('ALI-04')->postJson("/api/v1/orders/{$order}/payment-proof", [], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertJsonPath('message', 'Falta la foto del comprobante.');

        $this->assertDatabaseHas('orders', ['id_order' => $order, 'status' => 'pending_payment']);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    #[Test]
    public function ni_en_efectivo_ni_ya_cobrado_ni_el_de_otra_persona(): void
    {
        $cash = $this->newOrder('cash');
        $this->upload($cash)->assertConflict()->assertJsonPath('code', 'already_reviewed');

        $paid = $this->newOrder();
        \DB::table('orders')->where('id_order', $paid)->update(['status' => 'paid', 'paid_at' => now()]);
        $this->upload($paid)->assertConflict();

        $this->upload($this->newOrder(), seller: 'KIM-05')->assertNotFound();
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    #[Test]
    public function un_reintento_no_sube_el_archivo_dos_veces(): void
    {
        $order = $this->newOrder();
        $key = (string) Str::uuid();
        $file = UploadedFile::fake()->image('foto.jpg');

        $this->upload($order, $file, key: $key)->assertOk();
        $this->upload($order, $file, key: $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        Cache::flush();
        $this->upload($order, $file, key: $key)->assertOk()->assertJsonPath('data.status', 'payment_review');

        $this->assertCount(1, Storage::disk('local')->allFiles());
    }
}
