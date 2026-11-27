<?php

namespace Tests\Feature\Web;

use App\Models\Order;
use App\Models\User;
use App\Services\Store\ExpireWebOrders;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

class CheckoutTest extends WebTestCase
{
    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = User::factory()->customer()->create([
            'name' => 'Mariela', 'lastname' => 'Quispe', 'phone' => '71234567',
        ]);
        $this->asBuyer();

        Storage::fake('local');
        $this->setting('payment_qr_url', 'https://example.test/qr.png');
        $this->stock($this->product(), 100);
    }

    private function setting(string $key, mixed $value): void
    {
        DB::table('settings')->where('key', $key)->update(['value' => json_encode($value)]);
        $this->app->forgetScopedInstances();
    }

    private function asBuyer(?User $buyer = null): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        // Como al ingresar de verdad: no queda la huella de otra cuenta.
        $this->app['session.store']->forget('password_hash_customer');

        return $this->actingAs($buyer ?? $this->buyer, 'customer');
    }

    private function fillBag(int $quantity = 2): void
    {
        $this->post('/bolsa', ['product_id' => $this->product()->getKey(), 'quantity' => $quantity]);
    }

    /** @param  array<string, mixed>  $data */
    private function confirm(array $data = []): TestResponse
    {
        return $this->from('/pago')->post('/pago', [
            'delivery_type' => 'pickup',
            'address' => null,
            'expected_total' => '174.00',
            'client_uuid' => (string) Str::uuid(),
            ...$data,
        ]);
    }

    private function placeOrder(): Order
    {
        $this->fillBag();
        $this->confirm()->assertSessionHasNoErrors();

        return Order::query()->where('origin', 'web')->latest('created_at')->firstOrFail();
    }

    private function upload(Order $order, ?UploadedFile $file = null, ?string $reference = null): TestResponse
    {
        return $this->from("/pedido/{$order->public_token}")->post("/pedido/{$order->public_token}/comprobante", [
            'proof' => $file ?? UploadedFile::fake()->image('pago.jpg'),
            'reference' => $reference,
            'client_uuid' => (string) Str::uuid(),
        ]);
    }

    /** Jime confirma desde la app, con la misma API de siempre. */
    private function asJime(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$this->member('JIM-01')->createToken('Pruebas')->plainTextToken);
    }

    #[Test]
    public function sin_un_qr_cargado_la_tienda_no_recibe_pedidos_ni_aparta_stock(): void
    {
        $this->setting('payment_qr_url', null);
        $this->fillBag();

        $this->get('/bolsa')->assertSee('El pago en línea todavía no está disponible.');
        $this->get('/pago')->assertRedirect('/bolsa');
        $this->confirm()->assertRedirect('/bolsa');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, $this->level($this->product())->reserved);
    }

    #[Test]
    public function confirmar_aparta_el_stock_por_dos_horas_y_vacia_la_bolsa(): void
    {
        $this->freezeSecond();
        $this->fillBag();
        $this->get('/pago')->assertOk()->assertSee('Confirmar pedido · Bs 174');

        $response = $this->confirm();
        $order = Order::query()->where('origin', 'web')->firstOrFail();

        $response->assertRedirect("/pedido/{$order->public_token}");
        $this->assertSame('pending_payment', $order->status->value);
        $this->assertDatabaseHas('orders', [
            'id_order' => $order->getKey(),
            'customer_id' => $this->buyer->getKey(),
            'customer_name' => 'Mariela Quispe',
            'customer_phone' => '71234567',
        ]);
        $this->assertNull($order->seller_id);
        $this->assertSame('174.00', $order->total_bs);
        $this->assertTrue($order->expires_at->equalTo(now()->addHours(2)));
        $this->assertSame(2, $this->level($this->product())->reserved);
        $this->assertSame(100, $this->level($this->product())->stock);
        $this->assertDatabaseHas('payments', ['order_id' => $order->getKey(), 'method' => 'qr', 'status' => 'pending', 'amount_bs' => '174.00']);
        $this->assertDatabaseCount('commissions', 0);
        $this->get('/bolsa')->assertSee('Tu bolsa está vacía.');
    }

    #[Test]
    public function el_mismo_formulario_dos_veces_crea_un_solo_pedido(): void
    {
        $key = (string) Str::uuid();
        $this->fillBag();
        $this->confirm(['client_uuid' => $key]);
        $this->fillBag();
        $this->confirm(['client_uuid' => $key]);

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(2, $this->level($this->product())->reserved);
    }

    #[Test]
    public function el_servidor_pone_los_precios_y_avisa_si_cambiaron(): void
    {
        $this->fillBag();
        DB::table('products')->where('id_product', $this->product()->getKey())->update(['price_unit_bs' => 90]);

        $this->confirm()->assertRedirect('/pago')->assertSessionHasErrors('order');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, $this->level($this->product())->reserved);
    }

    #[Test]
    public function si_el_stock_ya_no_alcanza_no_se_crea_el_pedido(): void
    {
        $this->fillBag();
        $this->stock($this->product(), 10, 9);

        $this->confirm()->assertRedirect('/bolsa')->assertSessionHasErrors('order');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(9, $this->level($this->product())->reserved);
    }

    #[Test]
    public function guarda_la_oferta_aplicada_y_el_precio_anterior(): void
    {
        $promotion = $this->promotion($this->product());
        $this->fillBag();
        $this->confirm(['expected_total' => '170.00'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('order_items', [
            'promotion_id' => $promotion, 'unit_price_bs' => '85.00', 'list_price_bs' => '87.00',
        ]);
    }

    #[Test]
    public function el_delivery_pide_direccion_y_la_siguiente_vez_ya_viene_escrita(): void
    {
        $this->fillBag();
        $this->confirm(['delivery_type' => 'delivery'])->assertSessionHasErrors('address');
        $this->assertSame(0, Order::query()->count());

        $this->confirm(['delivery_type' => 'delivery', 'address' => 'Av. América 250'])->assertSessionHasNoErrors();

        $this->fillBag();
        $this->get('/pago')->assertSee('Av. América 250');
    }

    #[Test]
    public function los_datos_del_pedido_salen_de_la_cuenta_y_no_del_formulario(): void
    {
        $this->fillBag();
        $this->confirm(['name' => 'Otra Persona', 'phone' => '69999999', 'customer_id' => $this->member('ALI-04')->getKey()])
            ->assertSessionHasNoErrors();

        $order = Order::query()->firstOrFail();
        $this->assertSame('Mariela Quispe', $order->customer_name);
        $this->assertSame('71234567', $order->customer_phone);
        $this->assertDatabaseHas('orders', ['id_order' => $order->getKey(), 'customer_id' => $this->buyer->getKey(), 'seller_id' => null]);
    }

    #[Test]
    public function crear_otra_cuenta_con_el_mismo_whatsapp_no_salta_el_limite(): void
    {
        foreach (range(1, 3) as $attempt) {
            $this->placeOrder();
        }

        $this->asBuyer(User::factory()->customer()->create(['phone' => '71234567']));
        $this->fillBag();
        $this->confirm()->assertSessionHasErrors('order');

        $this->assertSame(3, Order::query()->count());
    }

    #[Test]
    public function un_mismo_numero_no_puede_acaparar_stock_con_muchos_pedidos(): void
    {
        foreach (range(1, 3) as $attempt) {
            $this->placeOrder();
        }

        $this->fillBag();
        $this->confirm()->assertSessionHasErrors('order');

        $this->assertSame(3, Order::query()->count());
    }

    #[Test]
    public function subir_cualquier_imagen_no_permite_saltarse_el_limite(): void
    {
        foreach (range(1, 3) as $attempt) {
            $this->upload($this->placeOrder())->assertSessionHasNoErrors();
        }

        $this->fillBag();
        $this->confirm()->assertSessionHasErrors('order');

        $this->assertSame(3, Order::query()->count());
    }

    #[Test]
    public function reenviar_un_formulario_ya_confirmado_lleva_al_mismo_pedido(): void
    {
        $key = (string) Str::uuid();
        $this->fillBag();
        $this->confirm(['client_uuid' => $key]);
        $order = Order::query()->firstOrFail();

        // La bolsa ya quedó vacía, como al volver atrás en el navegador.
        $this->confirm(['client_uuid' => $key])->assertRedirect("/pedido/{$order->public_token}");

        $this->assertSame(1, Order::query()->count());
    }

    #[Test]
    public function lo_no_pagado_vence_y_devuelve_el_stock(): void
    {
        $order = $this->placeOrder();

        $this->assertSame(0, app(ExpireWebOrders::class)->run());

        $this->travel(121)->minutes();
        $this->assertSame(1, app(ExpireWebOrders::class)->run());
        $this->assertSame(0, app(ExpireWebOrders::class)->run());

        $this->assertSame('expired', $order->refresh()->status->value);
        $this->assertSame(0, $this->level($this->product())->reserved);
        $this->assertSame(100, $this->level($this->product())->stock);
        $this->assertDatabaseHas('payments', ['order_id' => $order->getKey(), 'status' => 'rejected']);
        $this->get("/pedido/{$order->public_token}")->assertSee('Tu reserva venció');
    }

    #[Test]
    public function la_pagina_del_pedido_lo_vence_aunque_la_tarea_no_haya_corrido(): void
    {
        $order = $this->placeOrder();
        $this->travel(3)->hours();

        $this->upload($order)->assertSessionHasErrors('proof');
        $this->get("/pedido/{$order->public_token}")->assertSee('Si ya pagaste, no vuelvas a pagar');

        $this->assertSame(0, $this->level($this->product())->reserved);
    }

    #[Test]
    public function con_comprobante_en_revision_no_vence(): void
    {
        $order = $this->placeOrder();
        $this->upload($order, reference: 'ABC-1')->assertSessionHasNoErrors();
        $this->travel(2)->days();

        $this->assertSame(0, app(ExpireWebOrders::class)->run());
        $this->assertSame('payment_review', $order->refresh()->status->value);
        $this->assertSame(2, $this->level($this->product())->reserved);
    }

    #[Test]
    public function no_vence_las_ventas_de_la_app(): void
    {
        $order = $this->placeOrder();
        DB::table('orders')->where('id_order', $order->getKey())->update([
            'origin' => 'whatsapp', 'seller_id' => $this->member('ALI-04')->getKey(),
        ]);
        $this->travel(3)->days();

        $this->assertSame(0, app(ExpireWebOrders::class)->run());
        $this->assertSame(2, $this->level($this->product())->reserved);
    }

    #[Test]
    public function el_comprobante_se_guarda_en_privado_y_solo_acepta_imagenes(): void
    {
        $order = $this->placeOrder();

        $this->upload($order, UploadedFile::fake()->create('virus.php', 10, 'application/x-php'))->assertSessionHasErrors('proof');
        $this->upload($order, UploadedFile::fake()->image('grande.jpg')->size(6000))->assertSessionHasErrors('proof');
        $this->upload($order)->assertSessionHasNoErrors();

        $path = DB::table('payments')->where('order_id', $order->getKey())->value('proof_url');
        Storage::disk('local')->assertExists($path);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertDatabaseHas('payments', ['order_id' => $order->getKey(), 'status' => 'submitted', 'submitted_by' => $this->buyer->getKey()]);
        // El historial de la base sabe qué cliente lo subió.
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'payments', 'actor_id' => $this->buyer->getKey()]);
    }

    #[Test]
    public function cada_cliente_ve_y_paga_solo_sus_pedidos(): void
    {
        $order = $this->placeOrder();

        $this->get('/pedido/'.str_repeat('a', 32))->assertNotFound();
        $this->get("/pedido/{$order->public_token}")
            ->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/mis-pedidos')->assertSee($order->order_number);

        // Otro cliente, aunque tenga el enlace.
        $this->asBuyer(User::factory()->customer()->create());
        $this->get("/pedido/{$order->public_token}")->assertNotFound();
        $this->upload($order)->assertNotFound();
        $this->get('/mis-pedidos')->assertDontSee($order->order_number);

        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    #[Test]
    public function sin_sesion_el_enlace_de_un_pedido_pide_ingresar(): void
    {
        $order = $this->placeOrder();

        $this->app['auth']->guard('customer')->logout();

        $this->get("/pedido/{$order->public_token}")->assertRedirect('/ingresar');
        $this->upload($order)->assertRedirect('/ingresar');
    }

    #[Test]
    public function del_pago_en_la_web_a_la_entrega_en_almacen_el_stock_cuadra(): void
    {
        $order = $this->placeOrder();
        $this->upload($order, reference: 'TX-778')->assertSessionHasNoErrors();
        $payment = DB::table('payments')->where('order_id', $order->getKey())->value('id_payment');

        $this->asJime()->postJson("/api/v1/control/payments/{$payment}/confirm", [
            'amount_matches' => true, 'destination_ok' => true,
        ])->assertSuccessful();
        $this->assertSame('paid', $order->refresh()->status->value);

        $this->app['auth']->forgetGuards();
        $warehouse = $this->member('ISA-03')->createToken('Pruebas')->plainTextToken;
        foreach (['ready', 'delivered'] as $step) {
            $this->withHeader('Authorization', "Bearer {$warehouse}")
                ->postJson("/api/v1/warehouse/orders/{$order->getKey()}/{$step}")->assertSuccessful();
        }

        $this->assertSame('delivered', $order->refresh()->status->value);
        $this->assertSame(98, $this->level($this->product())->stock);
        $this->assertSame(0, $this->level($this->product())->reserved);
        $this->assertDatabaseHas('stock_movements', ['order_id' => $order->getKey(), 'type' => 'sale', 'quantity' => -2]);
    }

    #[Test]
    public function si_jime_rechaza_el_comprobante_el_cliente_tiene_otro_plazo(): void
    {
        $order = $this->placeOrder();
        $this->upload($order)->assertSessionHasNoErrors();
        $payment = DB::table('payments')->where('order_id', $order->getKey())->value('id_payment');
        $this->travel(5)->hours();

        $this->asJime()->postJson("/api/v1/control/payments/{$payment}/reject", ['reason' => 'No se ve el monto'])
            ->assertSuccessful();

        $this->assertSame(0, app(ExpireWebOrders::class)->run());
        $this->asBuyer();
        $this->get("/pedido/{$order->public_token}")->assertSee('No se ve el monto');

        $this->upload($order)->assertSessionHasNoErrors();
        $this->assertSame('payment_review', $order->refresh()->status->value);
        $this->assertSame(2, DB::table('payments')->where('order_id', $order->getKey())->count());
    }
}
