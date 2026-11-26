<?php

namespace Tests\Feature\Api;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

/** El reloj empieza el jueves 3 de diciembre (primera semana). */
class CashTest extends CampaignTestCase
{
    private function cashSale(string $seller = 'ALI-04'): string
    {
        return $this->sell($this->sale(with: ['payment_method' => 'cash']), $seller)->assertCreated()->json('data.id');
    }

    /** Una venta por QR con su comprobante, que Jime todavía no confirma. */
    private function qrWithProof(string $seller = 'ALI-04'): string
    {
        $id = $this->sell($this->sale(), $seller)->assertCreated()->json('data.id');
        $this->api($seller)->post("/api/v1/orders/{$id}/payment-proof", [
            'file' => UploadedFile::fake()->image('qr.jpg'),
        ], ['Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid()])->assertOk();

        return $id;
    }

    private function cash(string $path = 'today'): TestResponse
    {
        return $this->api('ISA-03')->getJson("/api/v1/cash/{$path}");
    }

    private function day(string $date): TestResponse
    {
        return $this->cash("days/{$date}");
    }

    /** @param  array<string, string>  $counted  por forma de pago */
    private function close(string $date, array $counted, ?string $note = null, ?string $key = null): TestResponse
    {
        return $this->api('ISA-03')->postJson('/api/v1/cash/closings', [
            'business_date' => $date,
            'lines' => collect($counted)
                ->map(fn (string $amount, string $method) => ['method' => $method, 'counted' => $amount])
                ->values()
                ->all(),
            'note' => $note,
        ], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    /** Lo recibido igual a lo que dicen los pedidos. */
    private function closeBalanced(string $date): TestResponse
    {
        $counted = collect($this->day($date)->json('data.lines'))->pluck('expected', 'method')->all();

        return $this->close($date, $counted)->assertOk();
    }

    /** @return array<string, array{int, string}> forma de pago → [pedidos, esperado] */
    private function expected(TestResponse $response): array
    {
        return collect($response->json('data.lines'))
            ->mapWithKeys(fn (array $line) => [$line['method'] => [$line['orders_count'], $line['expected']]])
            ->all();
    }

    private function linkedTo(string $orderId): ?string
    {
        return DB::table('payments')->where('order_id', $orderId)->value('cash_closing_id');
    }

    /** Jime observa el cierre, como lo hará su panel. */
    private function observe(string $date, string $note): void
    {
        DB::table('cash_closings')->where('business_date', $date)->update([
            'status' => 'observed',
            'review_note' => $note,
            'validated_by' => $this->member('JIM-01')->getKey(),
            'validated_at' => now(),
        ]);
    }

    #[Test]
    public function la_caja_de_hoy_suma_lo_cobrado_y_muestra_lo_pendiente(): void
    {
        $this->cashSale();
        $this->qrWithProof();
        $cancelled = $this->cashSale('KIM-05');
        DB::table('orders')->where('id_order', $cancelled)->update(['status' => 'cancelled']);
        $pending = $this->sell($this->sale(), 'KIM-05')->json('data');
        $rejected = $this->qrWithProof();
        DB::table('payments')->where('order_id', $rejected)->update(['status' => 'rejected', 'reject_reason' => 'Borroso']);
        DB::table('orders')->where('id_order', $rejected)->update(['status' => 'pending_payment']);

        $response = $this->cash()
            ->assertOk()
            ->assertJsonPath('data.closing_id', null)
            ->assertJsonPath('data.business_date', '2026-12-03')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.collected', '348.00')
            ->assertJsonPath('data.paid_orders', 2)
            ->assertJsonPath('data.pending_orders', 2)
            ->assertJsonPath('data.pending.0', [
                'order_id' => $pending['id'],
                'number' => $pending['number'],
                'customer_name' => 'Mariela Quispe',
                'payment_method' => 'qr',
                'total' => '174.00',
                'seller_name' => 'Kim',
                'detail' => 'sin comprobante',
            ])
            ->assertJsonPath('data.pending.1.detail', 'comprobante rechazado')
            ->assertJsonPath('data.pending_days', [
                ['date' => '2026-12-01', 'status' => 'open'],
                ['date' => '2026-12-02', 'status' => 'open'],
            ])
            ->assertJsonPath('data.closed_by_name', null)
            ->assertJsonPath('data.sent_at', null);

        // Siempre QR, transferencia y efectivo; la tarjeta solo si la hubo.
        $this->assertSame(
            ['qr' => [1, '174.00'], 'transfer' => [0, '0.00'], 'cash' => [1, '174.00']],
            $this->expected($response),
        );
        $this->assertNull($response->json('data.lines.0.counted'));
    }

    #[Test]
    public function enviar_exige_lo_recibido_y_explicar_la_diferencia(): void
    {
        $order = $this->cashSale();

        $this->close('2026-12-03', ['qr' => '0', 'cash' => '174'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Falta lo recibido por Transferencia.');
        $this->close('2026-12-03', ['qr' => '0', 'transfer' => '0', 'cash' => '124'], '   ')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'difference_needs_note');
        $this->close('2026-12-03', ['qr' => '0', 'transfer' => '0', 'cash' => '-1'])
            ->assertJsonValidationErrors('lines.2.counted');

        $this->close('2026-12-03', ['qr' => '0', 'transfer' => '0', 'cash' => '124.00'], ' Faltan Bs 50 de cambio. ')
            ->assertOk()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.note', 'Faltan Bs 50 de cambio.')
            ->assertJsonPath('data.closed_by_name', 'Isaí')
            ->assertJsonPath('data.sent_at', now()->toIso8601String())
            ->assertJsonPath('data.lines.2', [
                'method' => 'cash', 'orders_count' => 1, 'expected' => '174.00', 'counted' => '124.00', 'note' => null,
            ])
            ->assertJsonPath('data.pending_days.1.date', '2026-12-02');

        $closing = DB::table('cash_closings')->where('business_date', '2026-12-03')->value('id_cash_closing');
        $this->assertSame($closing, $this->linkedTo($order));
        $this->assertDatabaseHas('cash_closing_lines', [
            'cash_closing_id' => $closing, 'method' => 'cash', 'difference_bs' => -50,
        ]);
        $this->close('2026-12-03', ['qr' => '0', 'transfer' => '0', 'cash' => '174'])
            ->assertConflict()
            ->assertJsonPath('code', 'closing_already_sent');
        $this->api('ISA-03')->getJson('/api/v1/home')->assertJsonPath('data.cash_today', [
            'collected' => '174.00', 'paid_orders' => 1, 'pending_orders' => 0, 'status' => 'sent', 'pending_days' => 2,
        ]);
    }

    #[Test]
    public function un_reintento_del_envio_no_se_repite(): void
    {
        $key = (string) Str::uuid();
        $zero = ['qr' => '0', 'transfer' => '0', 'cash' => '0'];

        $this->close('2026-12-03', $zero, key: $key)->assertOk();
        $this->close('2026-12-03', $zero, key: $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        Cache::flush();
        $this->close('2026-12-03', $zero, key: $key)->assertConflict();

        $this->assertSame(1, DB::table('cash_closings')->count());
    }

    #[Test]
    public function un_cobro_despues_de_enviar_pasa_al_siguiente_cierre(): void
    {
        $early = $this->cashSale();
        $this->closeBalanced('2026-12-03');
        $this->travelTo('2026-12-03 19:00:00');
        $late = $this->cashSale();

        // El enviado queda como se envió.
        $this->cash()->assertJsonPath('data.collected', '174.00');
        $this->assertNull($this->linkedTo($late));

        // Un día anterior que falta cerrar no se lo lleva.
        $this->travelTo('2026-12-04 10:00:00');
        $this->day('2026-12-02')->assertJsonPath('data.collected', '0.00');
        $this->cash()->assertJsonPath('data.collected', '174.00')->assertJsonPath('data.paid_orders', 1);

        $this->closeBalanced('2026-12-04');
        $this->day('2026-12-03')->assertJsonPath('data.collected', '174.00');
        $this->assertSame(
            DB::table('cash_closings')->where('business_date', '2026-12-04')->value('id_cash_closing'),
            $this->linkedTo($late),
        );
        $this->assertNotSame($this->linkedTo($early), $this->linkedTo($late));
    }

    #[Test]
    public function el_cobro_atrasado_se_lo_lleva_el_primero_que_se_envie(): void
    {
        $this->travelTo('2026-12-02 12:00:00');
        $this->closeBalanced('2026-12-02');
        $this->travelTo('2026-12-02 20:00:00');
        $late = $this->cashSale();
        $this->travelTo('2026-12-04 10:00:00');

        $this->day('2026-12-03')->assertJsonPath('data.collected', '174.00');
        $this->cash()->assertJsonPath('data.collected', '174.00');

        $this->closeBalanced('2026-12-04');

        $this->day('2026-12-03')->assertJsonPath('data.collected', '0.00');
        $this->assertNotNull($this->linkedTo($late));
    }

    #[Test]
    public function lo_cobrado_antes_de_la_campaña_entra_en_el_primer_dia(): void
    {
        $this->travelTo('2026-11-30 17:00:00');
        $order = $this->cashSale();

        $this->cash()->assertJsonPath('data.collected', '174.00')->assertJsonPath('data.pending_days', []);
        $this->close('2026-11-30', ['qr' => '0', 'transfer' => '0', 'cash' => '174'])->assertNotFound();

        $this->travelTo('2026-12-01 18:00:00');
        $this->cash()->assertJsonPath('data.collected', '174.00');
        $this->closeBalanced('2026-12-01');
        $this->assertNotNull($this->linkedTo($order));
    }

    #[Test]
    public function lo_observado_se_recalcula_y_se_vuelve_a_enviar(): void
    {
        $cashOrder = $this->cashSale();
        $qrOrder = $this->qrWithProof();
        $this->close('2026-12-03', ['qr' => '174', 'transfer' => '0', 'cash' => '100'], 'Falta cambio')->assertOk();
        $this->observe('2026-12-03', 'Escribe de qué venta es.');

        // Después de enviar: Jime rechaza el QR y entra otro efectivo.
        DB::table('payments')->where('order_id', $qrOrder)->update(['status' => 'rejected', 'reject_reason' => 'Otro monto']);
        DB::table('orders')->where('id_order', $qrOrder)->update(['status' => 'pending_payment']);
        $later = $this->cashSale('KIM-05');

        $this->cash()
            ->assertJsonPath('data.status', 'observed')
            ->assertJsonPath('data.review_note', 'Escribe de qué venta es.')
            ->assertJsonPath('data.note', 'Falta cambio')
            ->assertJsonPath('data.lines.0.expected', '0.00')
            ->assertJsonPath('data.lines.0.counted', '174.00')
            ->assertJsonPath('data.lines.2.expected', '348.00')
            ->assertJsonPath('data.lines.2.counted', '100.00');

        $this->close('2026-12-03', ['qr' => '0', 'transfer' => '0', 'cash' => '348'])
            ->assertOk()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.review_note', null)
            ->assertJsonPath('data.note', null);

        $closing = $this->linkedTo($cashOrder);
        $this->assertNotNull($closing);
        $this->assertSame($closing, $this->linkedTo($later));
        $this->assertNull($this->linkedTo($qrOrder));
        $this->assertSame(1, DB::table('cash_closings')->count());
        $this->assertNull(DB::table('cash_closings')->value('validated_by'));
    }

    #[Test]
    public function un_cobro_confirmado_cuenta_el_dia_que_llego_el_dinero(): void
    {
        $order = $this->sell($this->sale())->json('data.id');
        $this->confirmPayment($order);
        DB::table('payments')->where('order_id', $order)->update(['received_at' => '2026-12-02 23:30:00']);

        $this->day('2026-12-02')->assertJsonPath('data.collected', '174.00');
        $this->cash()->assertJsonPath('data.collected', '0.00');
    }

    #[Test]
    public function la_tarjeta_aparece_solo_con_pagos_de_la_web(): void
    {
        $id = (string) Str::uuid7();
        DB::table('orders')->insert([
            'id_order' => $id, 'origin' => 'web', 'status' => 'paid', 'customer_name' => 'Luis',
            'paid_at' => now(), 'created_at' => now(),
        ]);
        DB::table('payments')->insert([
            'order_id' => $id, 'method' => 'card', 'status' => 'confirmed', 'amount_bs' => 90,
            'gateway_provider' => 'prueba', 'gateway_transaction_id' => 'tx-1',
            'submitted_at' => now(), 'reviewed_at' => now(), 'received_at' => now(),
        ]);

        $this->assertSame([1, '90.00'], $this->expected($this->cash())['card']);
    }

    #[Test]
    public function solo_los_dias_de_la_campaña_hasta_hoy_tienen_caja(): void
    {
        $this->day('2026-12-01')
            ->assertOk()
            ->assertJsonPath('data.business_date', '2026-12-01')
            ->assertJsonMissingPath('data.pending_days');

        foreach (['2026-11-30', '2026-12-04', '2026-13-01', '2026-12-32'] as $date) {
            $this->day($date)->assertNotFound()->assertJsonPath('message', 'Ese día no tiene caja.');
        }

        $this->close('2026-12-04', ['qr' => '0', 'transfer' => '0', 'cash' => '0'])->assertNotFound();
    }

    #[Test]
    public function solo_almacen_ve_y_cierra_la_caja(): void
    {
        foreach (['ALI-04', 'JIM-01', 'DIR-08'] as $code) {
            $this->api($code)->getJson('/api/v1/cash/today')->assertForbidden();
            $this->api($code)->postJson('/api/v1/cash/closings', [])->assertForbidden();
        }
    }
}
