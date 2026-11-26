<?php

namespace Tests\Feature\Api;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * La campaña con atajos para el panel de Jime. Las decisiones de Dirección
 * (fase 6) se escriben directo en la base, con sus reglas.
 */
abstract class ControlTestCase extends CampaignTestCase
{
    protected function control(string $path, string $code = 'JIM-01'): TestResponse
    {
        return $this->api($code)->getJson("/api/v1/control/{$path}");
    }

    /** @param  array<string, mixed>  $data */
    protected function act(string $path, array $data = [], string $code = 'JIM-01'): TestResponse
    {
        return $this->api($code)->postJson("/api/v1/control/{$path}", $data);
    }

    protected function cashSale(string $seller = 'ALI-04'): string
    {
        return $this->sell($this->sale(with: ['payment_method' => 'cash']), $seller)->assertCreated()->json('data.id');
    }

    /**
     * Una venta con su comprobante esperando a Jime.
     *
     * @return array{order: string, payment: string}
     */
    protected function proofSale(string $method = 'qr', ?string $reference = null, string $seller = 'ALI-04'): array
    {
        $order = $this->sell($this->sale(with: ['payment_method' => $method]), $seller)->assertCreated()->json('data.id');
        $this->api($seller)->post("/api/v1/orders/{$order}/payment-proof", array_filter([
            'file' => UploadedFile::fake()->image('comprobante.jpg'),
            'reference' => $reference,
        ]), ['Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid()])->assertOk();

        return ['order' => $order, 'payment' => $this->paymentOf($order)];
    }

    protected function paymentOf(string $orderId): string
    {
        return DB::table('payments')->where('order_id', $orderId)->orderByDesc('created_at')->value('id_payment');
    }

    protected function confirm(string $paymentId): void
    {
        $this->act("payments/{$paymentId}/confirm", ['amount_matches' => true, 'destination_ok' => true])->assertOk();
    }

    /** Isaí envía la caja de ese día tal como dicen los pedidos. */
    protected function sendCash(string $date): string
    {
        $lines = $this->api('ISA-03')->getJson("/api/v1/cash/days/{$date}")->assertOk()->json('data.lines');
        $this->api('ISA-03')->postJson('/api/v1/cash/closings', [
            'business_date' => $date,
            'lines' => array_map(fn (array $line) => ['method' => $line['method'], 'counted' => $line['expected']], $lines),
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

        return DB::table('cash_closings')->where('business_date', $date)->value('id_cash_closing');
    }

    /** Carga el valor real de todo el equipo (por defecto, la meta). */
    protected function loadTeam(int $week = 1, array $except = []): void
    {
        foreach (['AIL-02', 'ISA-03', 'ALI-04', 'KIM-05', 'SIS-06', 'SIS-07'] as $code) {
            if (! in_array($code, $except, true)) {
                $this->loadActuals($code, week: $week);
            }
        }
    }

    /** Deja las 4 entregas de un ingeniero validadas y a tiempo. */
    protected function deliverAll(string $code): void
    {
        DB::table('system_deliverables')->where('user_id', $this->member($code)->getKey())->update([
            'delivered_on' => DB::raw('due_on'),
            'evidence_url' => 'https://ejemplo.com/entrega',
            'jime_validated' => true,
            'validated_by' => $this->member('JIM-01')->getKey(),
            'validated_at' => now(),
        ]);
    }

    /** @param  array<string, string>  $closePending  código → motivo */
    protected function sendWeek(int $week = 1, array $closePending = [], bool $duplicates = true): TestResponse
    {
        return $this->act("weekly-closing/{$week}/send", [
            'duplicates_cleared' => $duplicates,
            'close_pending' => collect($closePending)
                ->map(fn (string $reason, string $code) => ['member_id' => $this->member($code)->getKey(), 'reason' => $reason])
                ->values()
                ->all(),
            'observations' => null,
        ]);
    }

    /** Dirección autoriza el cierre enviado. */
    protected function directionAuthorizes(int $week = 1): void
    {
        DB::table('weekly_closings')->where('week_id', $this->week($week)->getKey())->update([
            'status' => 'authorized',
            'direction_approved' => true,
            'authorized_by' => $this->member('DIR-08')->getKey(),
            'authorized_at' => now(),
        ]);
    }

    /** Dirección lo devuelve: los cierres a mano se indican de nuevo. */
    protected function directionReturns(string $reason, int $week = 1): void
    {
        DB::table('weekly_closings')->where('week_id', $this->week($week)->getKey())
            ->update(['status' => 'returned', 'return_reason' => $reason]);
        DB::table('weekly_member_results')->where('week_id', $this->week($week)->getKey())->delete();
    }

    protected function setRate(string $code, ?float $percent): void
    {
        DB::table('products')->where('id_product', $this->product($code)->getKey())->update(['commission_pct' => $percent]);
    }

    protected function submitEvidence(string $code, string $indicator, int $week = 1): string
    {
        $result = $this->resultId($code, $indicator, $week);
        $this->api($code)->postJson("/api/v1/me/kpi-results/{$result}/evidence", [
            'kind' => 'link',
            'url' => 'https://drive.google.com/evidencia',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        return $result;
    }

    /** Sistemas envía su primera entrega. */
    protected function submitDeliverable(string $code = 'SIS-06', string $deliveredOn = '2026-12-02'): string
    {
        $id = DB::table('system_deliverables')->where('user_id', $this->member($code)->getKey())
            ->orderBy('sort_order')->value('id_system_deliverable');
        $this->api($code)->postJson("/api/v1/me/deliverables/{$id}/submission", [
            'delivered_on' => $deliveredOn,
            'url' => 'https://github.com/traigo/entrega',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

        return $id;
    }
}
