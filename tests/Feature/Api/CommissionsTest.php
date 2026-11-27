<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

/** La liquidación mensual. Aquí el tomatodo 8107 paga 5 %: Bs 8,70 por venta. */
class CommissionsTest extends ControlTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setRate('8107', 5);
    }

    private function month(string $month): TestResponse
    {
        return $this->control("commissions?month={$month}")->assertOk();
    }

    /** @return array<string, array{int, ?string, string}> nombre → [ventas, monto, ajustes] */
    private function rows(TestResponse $response): array
    {
        return collect($response->json('data.members'))
            ->mapWithKeys(fn (array $row) => [$row['name'] => [$row['paid_sales'], $row['amount'], $row['adjustments']]])
            ->all();
    }

    private function paidSale(string $seller = 'ALI-04'): array
    {
        $proof = $this->proofSale(seller: $seller);
        $this->confirm($proof['payment']);

        return $proof;
    }

    #[Test]
    public function el_mes_suma_lo_cobrado_por_quienes_venden(): void
    {
        $this->paidSale();
        $this->cashSale('KIM-05'); // sin validar la caja, todavía no se gana

        $response = $this->month('2026-12')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.rates_pending', false)
            ->assertJsonPath('data.total', '8.70')
            ->assertJsonPath('data.settles_on', '2026-12-31')
            ->assertJsonPath('data.return_reason', null);
        $this->assertSame(['Alison' => [1, '8.70', '0.00'], 'Kim' => [0, '0.00', '0.00']], $this->rows($response));

        // Un mes sin ventas (antes de la campaña) también responde.
        $this->month('2026-11')->assertJsonPath('data.total', '0.00');
        $this->control('commissions?month=diciembre')->assertUnprocessable();
    }

    #[Test]
    public function se_envia_cuando_el_mes_termina_y_con_todos_los_porcentajes(): void
    {
        $this->paidSale();
        $this->act('commissions/2026-12/send')
            ->assertConflict()->assertJsonPath('code', 'month_not_ended');

        $this->travelTo('2027-01-02 10:00:00');
        $this->act('commissions/2026-11/send')->assertJsonPath('code', 'nothing_to_settle');

        $this->setRate('8107', null);
        $pending = $this->paidSale('KIM-05');
        \App\Services\Control\Commissions::forOrder($pending['order'])->update(['earned_at' => '2026-12-20 10:00:00']);
        $this->month('2026-12')
            ->assertJsonPath('data.rates_pending', true)
            ->assertJsonPath('data.total', null)
            ->assertJsonPath('data.members.1.amount', null);
        $this->act('commissions/2026-12/send')->assertJsonPath('code', 'rates_pending');

        // Dirección aprueba el % (fase 6) y las ventas sin % lo toman.
        DB::table('commissions')->whereNull('rate_pct')
            ->update(['rate_pct' => 5, 'amount_bs' => DB::raw('round(base_bs * 5 / 100, 2)')]);

        $this->act('commissions/2026-12/send')
            ->assertOk()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.total', '17.40');
        $this->act('commissions/2026-12/send')->assertJsonPath('code', 'closing_already_sent');
        $this->assertSame(2, DB::table('commissions')->whereNotNull('settlement_id')->count());
    }

    #[Test]
    public function lo_que_se_gana_despues_de_enviar_el_mes_pasa_al_siguiente(): void
    {
        $this->travelTo('2026-12-30 18:00:00');
        $late = $this->proofSale('qr', seller: 'KIM-05');
        $this->paidSale();

        // Sin enviar diciembre, un cobro confirmado en enero sigue siendo de diciembre.
        $this->travelTo('2027-01-02 10:00:00');
        $other = $this->proofSale('transfer');
        DB::table('payments')->where('id_payment', $other['payment'])->update(['submitted_at' => '2026-12-31 19:00:00']);
        $this->confirm($other['payment']);
        $this->month('2026-12')->assertJsonPath('data.total', '17.40');

        $this->act('commissions/2026-12/send')->assertOk();
        $this->confirm($late['payment']);

        // Diciembre queda como se envió; el cobro atrasado va en enero.
        $this->month('2026-12')->assertJsonPath('data.total', '17.40');
        $this->assertSame([1, '8.70', '0.00'], $this->rows($this->month('2027-01'))['Kim']);

        $this->travelTo('2027-02-01 09:00:00');
        $this->act('commissions/2027-01/send')->assertOk()->assertJsonPath('data.total', '8.70');
    }

    #[Test]
    public function una_devolucion_descuenta_en_el_mes_en_que_se_hace(): void
    {
        $sale = $this->paidSale();
        $this->travelTo('2027-01-02 10:00:00');
        $this->act('commissions/2026-12/send')->assertOk();

        $this->act("orders/{$sale['order']}/return", ['reason' => 'Defecto'])->assertNoContent();

        $this->assertSame([0, '-8.70', '-8.70'], $this->rows($this->month('2027-01'))['Alison']);
        $this->month('2026-12')->assertJsonPath('data.total', '8.70');
    }

    #[Test]
    public function se_marca_pagada_solo_autorizada(): void
    {
        $this->paidSale();
        $this->travelTo('2027-01-02 10:00:00');
        $this->act('commissions/2026-12/paid')
            ->assertConflict()->assertJsonPath('message', 'Todavía no lo autorizó Dirección.');
        $this->act('commissions/2026-12/send')->assertOk();

        DB::table('commission_settlements')->update([
            'status' => 'authorized', 'authorized_by' => $this->member('DIR-08')->getKey(), 'authorized_at' => now(),
        ]);

        $this->act('commissions/2026-12/paid')->assertOk()->assertJsonPath('data.status', 'paid');
        $this->act('commissions/2026-12/paid')->assertJsonPath('message', 'Esa liquidación ya está pagada.');
        $this->act('commissions/2026-12/send', [], 'DIR-08')->assertForbidden();
    }
}
