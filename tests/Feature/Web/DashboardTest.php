<?php

namespace Tests\Feature\Web;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\Control\ControlOrders;
use App\Services\Control\Payments;
use App\Services\Sales\SubmitProof;
use App\Services\Store\PlaceWebOrder;
use App\Services\Warehouse\Dispatches;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

class DashboardTest extends WebTestCase
{
    private function order(): Order
    {
        $buyer = User::factory()->customer()->create();
        return app(PlaceWebOrder::class)->place($buyer, [$this->product()->getKey() => 2], [
            'delivery_type' => 'delivery', 'address' => 'Av. América', 'latitude' => '-17.38', 'longitude' => '-66.16', 'reference' => 'Puerta azul',
        ], 17400, (string) Str::uuid());
    }

    private function pay(Order $order): void
    {
        $buyer = User::findOrFail($order->customer_id);
        app(SubmitProof::class)->attach(fn () => Order::query()->lockForUpdate()->findOrFail($order->getKey()), $buyer, UploadedFile::fake()->image('pago.png'), null, (string) Str::uuid());
        app(Payments::class)->confirm($this->member('JIM-01'), $order->currentPayment()->firstOrFail()->getKey(), true, true, now());
    }

    #[Test]
    public function estadisticas_distinguen_reservas_cobros_entregas_y_devoluciones(): void
    {
        Storage::fake('local');
        $this->stock($this->product(), 20);
        $paid = $this->order();
        $pending = $this->order();
        $returned = $this->order();
        $this->pay($paid);
        $this->pay($returned);
        app(ControlOrders::class)->returnOrder($this->member('JIM-01'), $returned->getKey(), 'Prueba devolución');
        $dispatch = app(Dispatches::class);
        $dispatch->ready($this->member('ISA-03'), $paid->getKey());
        $dispatch->deliver($this->member('ISA-03'), $paid->getKey());

        $this->actingAs($this->member('DIR-08'), 'web')->get('/equipo/estadisticas')
            ->assertOk()->assertViewHas('ordersCount', 3)->assertViewHas('collectedCents', 17400)
            ->assertViewHas('paidCount', 1)->assertViewHas('cancelledCount', 1)->assertViewHas('deliveredUnits', 2)
            ->assertViewHas('stock', fn ($stock) => $stock->stock === 18 && $stock->reserved === 2)
            ->assertViewHas('topProducts', fn ($products) => $products->count() === 1 && $products->first()->units === 2);
        $this->assertSame(OrderStatus::PendingPayment, $pending->fresh()->status);

        $this->get('/equipo/estadisticas?channel=team')->assertViewHas('collectedCents', 0)->assertViewHas('ordersCount', 0);
    }

    #[Test]
    public function el_periodo_se_aplica_a_cobros_y_muestra_delivery_pendiente_con_mapa(): void
    {
        Storage::fake('local');
        $this->stock($this->product(), 20);
        $order = $this->order();
        $this->pay($order);
        DB::table('payments')->where('order_id', $order->getKey())->update(['received_at' => now()->subDays(40)]);
        $this->actingAs($this->member('DIR-08'), 'web')->get('/equipo/estadisticas')
            ->assertOk()->assertViewHas('collectedCents', 0)->assertSee('Abrir punto de entrega')->assertSee('Puerta azul');
        $this->get('/equipo/estadisticas?from='.today()->subDays(45)->toDateString())
            ->assertViewHas('collectedCents', 17400);
    }

    #[Test]
    public function el_panel_valida_fechas_y_excluye_clientes(): void
    {
        $this->actingAs(User::factory()->customer()->create(), 'customer')->get('/equipo/estadisticas')->assertRedirect('/equipo/ingresar');
        $this->actingAs($this->member('DIR-08'), 'web')->from('/equipo/estadisticas')
            ->get('/equipo/estadisticas?from=2020-01-01&until=2022-01-01')->assertSessionHasErrors('from');
        $this->get('/equipo/estadisticas?channel=bad')->assertSessionHasErrors('channel');
    }
}
