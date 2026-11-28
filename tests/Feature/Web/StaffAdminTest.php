<?php

namespace Tests\Feature\Web;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

class StaffAdminTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['services.cloudinary.url' => null]);
    }

    private function as(string $code): static
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetScopedInstances();
        $this->actingAs($this->member($code), 'web');

        return $this;
    }

    /** @return array<string, mixed> */
    private function newProduct(array $data = []): array
    {
        return [
            'item_code' => 'nuevo-01',
            'name' => 'Tomatodo térmico',
            'category_id' => DB::table('categories')->where('slug', 'tomatodos')->value('id_category'),
            'price_unit_bs' => '90',
            'price_wholesale_bs' => '84',
            'wholesale_min_qty' => 24,
            ...$data,
        ];
    }

    /** @return array<string, mixed> */
    private function newPromotion(array $data = []): array
    {
        return [
            'name' => 'Puerta de Messi',
            'discount_type' => 'fixed_price',
            'discount_value' => '84',
            'calendar_day' => 3,
            'starts_at' => now()->subHour()->format('Y-m-d\TH:i'),
            'ends_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'product_ids' => [$this->product()->getKey()],
            ...$data,
        ];
    }

    private function pendingPromotion(): string
    {
        $data = $this->newPromotion();
        $products = $data['product_ids'];
        unset($data['product_ids']);
        return app(\App\Services\Store\PromotionEditor::class)->create($this->member('JIM-01'), [
            ...$data, 'applies_to_wholesale' => false,
        ], $products);
    }

    #[Test]
    public function almacen_no_entra_a_la_administracion(): void
    {
        foreach (['productos', 'productos/nuevo', 'ofertas', 'configuracion', 'autorizaciones'] as $page) {
            $this->as('ISA-03')->get("/equipo/{$page}")->assertRedirect('/equipo/ingresar');
        }
    }

    #[Test]
    public function todas_las_pantallas_del_panel_abren_para_quien_corresponde(): void
    {
        $product = $this->product()->getKey();

        foreach (['estadisticas', 'inventario', "inventario/{$product}", 'productos', 'productos/nuevo', "productos/{$product}", 'ofertas', 'configuracion', 'cotizaciones'] as $page) {
            $this->as('DIR-08')->get("/equipo/{$page}")->assertOk();
        }

        $this->as('DIR-08')->get('/equipo/autorizaciones')->assertOk();
        $this->as('DIR-08')->get('/equipo/ofertas/nueva')->assertForbidden();
        $this->as('JIM-01')->get('/equipo/autorizaciones')->assertRedirect('/equipo/ingresar');
    }

    #[Test]
    public function un_producto_nuevo_nace_fuera_de_la_venta_y_lo_publica_direccion(): void
    {
        $this->as('DIR-08')->post('/equipo/productos', $this->newProduct())->assertSessionHasNoErrors();

        $product = DB::table('products')->where('item_code', 'NUEVO-01')->first();
        $this->assertFalse($product->is_active);
        $this->assertDatabaseHas('inventory', ['product_id' => $product->id_product, 'stock' => 0]);
        $this->get('/producto/'.$product->slug)->assertNotFound();

        $this->as('JIM-01')->from('/equipo/productos')
            ->patch("/equipo/productos/{$product->id_product}/venta", ['active' => 1])->assertRedirect('/equipo/ingresar');
        $this->as('DIR-08')->patch("/equipo/productos/{$product->id_product}/venta", ['active' => 1]);

        $this->get('/producto/'.$product->slug)->assertOk();
    }

    #[Test]
    public function el_alta_respeta_las_reglas_de_precios_y_el_item_unico(): void
    {
        $this->as('DIR-08');

        $this->from('/equipo/productos/nuevo')->post('/equipo/productos', $this->newProduct(['price_wholesale_bs' => '95']))
            ->assertSessionHasErrors('form');
        $this->post('/equipo/productos', $this->newProduct(['item_code' => '8107']))->assertSessionHasErrors('item_code');
        $this->post('/equipo/productos', $this->newProduct(['wholesale_min_qty' => null]))->assertSessionHasErrors('wholesale_min_qty');

        $this->assertSame(62, DB::table('products')->count());
    }

    #[Test]
    public function un_precio_solo_cambia_cuando_direccion_autoriza(): void
    {
        $product = $this->product();
        $url = "/equipo/productos/{$product->getKey()}";

        // Editar los datos no toca los precios aunque se envíen.
        $this->as('DIR-08')->patch($url, [
            'name' => 'Tomatodos de Messi', 'category_id' => $product->category_id, 'price_unit_bs' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertSame('87.00', $this->product()->price_unit_bs);

        $this->as('DIR-08')->post("{$url}/precios", ['price_unit' => '91'])->assertForbidden();
        app(\App\Services\Control\PriceChanges::class)->propose($this->member('JIM-01'), $product->getKey(), ['price_unit' => '91'], 'Sube el costo');
        $this->assertSame('87.00', $this->product()->price_unit_bs);

        $request = DB::table('price_change_requests')->value('id_price_change_request');
        $this->as('DIR-08')->get("/equipo/autorizaciones/price_change/{$request}")->assertOk()->assertSee('Bs 91');
        $this->post("/equipo/autorizaciones/price_change/{$request}/autorizar")->assertSessionHasNoErrors();

        $this->assertSame('91.00', $this->product()->price_unit_bs);
    }

    #[Test]
    public function una_oferta_se_publica_recien_cuando_direccion_la_autoriza(): void
    {
        $id = $this->pendingPromotion();

        // Sin autorizar, la tienda sigue con el precio de siempre.
        $this->get('/producto/'.$this->product()->slug)->assertViewHas('product', fn ($card) => $card->priceCents === 8700 && $card->listCents === null);

        $this->as('DIR-08')->get("/equipo/autorizaciones/promotion/{$id}")->assertOk();
        $this->post("/equipo/autorizaciones/promotion/{$id}/autorizar")->assertSessionHasNoErrors();

        $this->get('/producto/'.$this->product()->slug)->assertSee('Oferta')->assertSee('Bs 84');
        $this->get('/')->assertSee('Puerta de Messi');
    }

    #[Test]
    public function direccion_consulta_y_retira_ofertas_sin_saltarse_la_separacion_de_roles(): void
    {
        $id = $this->pendingPromotion();
        $this->as('DIR-08')->get('/equipo/ofertas')->assertOk()->assertSee('Puerta de Messi')->assertDontSee('Nueva oferta');
        $this->post('/equipo/ofertas', $this->newPromotion())->assertForbidden();
        $this->patch('/equipo/ofertas/'.$id.'/retirar')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('promotions', ['id_promotion' => $id, 'is_active' => false, 'calendar_day' => null]);
    }

    #[Test]
    public function el_historial_de_la_app_cuenta_lo_que_se_hace_en_el_panel(): void
    {
        $this->as('DIR-08')->post('/equipo/productos', $this->newProduct());
        $this->pendingPromotion();
        $this->patch('/equipo/ofertas/'.DB::table('promotions')->value('id_promotion').'/retirar');
        $this->as('DIR-08')->patch('/equipo/configuracion', ['order_reservation_hours' => 3]);

        $this->app['auth']->forgetGuards();
        $token = $this->member('JIM-01')->createToken('Pruebas')->plainTextToken;

        $descriptions = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/control/audit')
            ->assertOk()
            ->json('data.*.description');

        $this->assertContains('Creó el producto Tomatodo térmico', $descriptions);
        $this->assertContains('Creó la promoción «Puerta de Messi»', $descriptions);
        $this->assertContains('Retiró la promoción «Puerta de Messi»', $descriptions);
        $this->assertContains('Cambió la configuración «order_reservation_hours»', $descriptions);
    }

    #[Test]
    public function las_fotos_se_suben_se_ordenan_y_se_borran(): void
    {
        $url = '/equipo/productos/'.$this->product()->getKey().'/fotos';
        $this->as('DIR-08')->from('/equipo/productos');

        $this->post($url, ['photo' => UploadedFile::fake()->image('a.jpg', 800, 800)])->assertSessionHasNoErrors();
        $this->post($url, ['photo' => UploadedFile::fake()->image('b.png', 800, 800)])->assertSessionHasNoErrors();
        $this->post($url, ['photo' => UploadedFile::fake()->image('chica.jpg', 50, 50)])->assertSessionHasErrors('photo');
        $this->post($url, ['photo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertSessionHasErrors('photo');
        $this->assertCount(2, Storage::disk('public')->allFiles());

        [$first, $second] = DB::table('product_images')->orderBy('position')->get()->all();
        $this->patch("{$url}/{$second->id_product_image}");
        $this->assertSame($second->id_product_image, DB::table('product_images')->orderBy('position')->value('id_product_image'));
        $this->get('/producto/'.$this->product()->slug)->assertSee($second->url, false);

        $this->delete("{$url}/{$first->id_product_image}");
        $this->assertDatabaseCount('product_images', 1);
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    #[Test]
    public function con_cloudinary_configurado_las_fotos_van_alla_con_envios_firmados(): void
    {
        config(['services.cloudinary.url' => 'cloudinary://llave123:secreto456@mi-nube']);
        Http::fake([
            'api.cloudinary.com/*/upload' => Http::response([
                'public_id' => 'traigo/productos/x/abc', 'secure_url' => 'https://res.cloudinary.com/mi-nube/abc.jpg',
            ]),
            'api.cloudinary.com/*/destroy' => Http::response(['result' => 'ok']),
        ]);
        $url = '/equipo/productos/'.$this->product()->getKey().'/fotos';

        $this->as('DIR-08')->post($url, ['photo' => UploadedFile::fake()->image('a.jpg', 800, 800)])->assertSessionHasNoErrors();
        $image = DB::table('product_images')->first();
        $this->assertSame('https://res.cloudinary.com/mi-nube/abc.jpg', $image->url);

        $this->delete("{$url}/{$image->id_product_image}");

        Http::assertSentCount(2);
        // El secreto firma, pero nunca viaja.
        Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->body(), 'secreto456'));
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    #[Test]
    public function si_cloudinary_falla_no_queda_nada_a_medias(): void
    {
        config(['services.cloudinary.url' => 'cloudinary://llave123:secreto456@mi-nube']);
        Http::fake(['api.cloudinary.com/*' => Http::response(['error' => ['message' => 'Invalid Signature']], 401)]);

        $this->as('DIR-08')->from('/equipo/productos')
            ->post('/equipo/productos/'.$this->product()->getKey().'/fotos', ['photo' => UploadedFile::fake()->image('a.jpg', 800, 800)])
            ->assertSessionHasErrors('form');

        $this->assertDatabaseCount('product_images', 0);
    }

    #[Test]
    public function solo_direccion_cambia_el_qr_y_con_el_se_activa_el_pago(): void
    {
        $this->as('JIM-01')->post('/equipo/configuracion/qr', ['qr' => UploadedFile::fake()->image('qr.png', 500, 500)])
            ->assertRedirect('/equipo/ingresar');
        $this->as('JIM-01')->delete('/equipo/configuracion/qr')->assertRedirect('/equipo/ingresar');

        $this->as('DIR-08')->post('/equipo/configuracion/qr', ['qr' => UploadedFile::fake()->image('qr.png', 500, 500)])
            ->assertSessionHasNoErrors();

        $url = json_decode(DB::table('settings')->where('key', 'payment_qr_url')->value('value'));
        $this->assertNotEmpty($url);
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'settings', 'entity_id' => 'payment_qr_url', 'actor_id' => $this->member('DIR-08')->getKey(),
        ]);
    }

    #[Test]
    public function la_configuracion_se_refleja_en_la_tienda_y_solo_anota_lo_que_cambia(): void
    {
        $this->as('DIR-08')->patch('/equipo/configuracion', [
            'store_whatsapp' => '+591 70000000',
            'store_tiktok' => '@i_traigo',
            'pickup_address' => 'Av. Heroínas 100',
            'order_reservation_hours' => 2,
        ])->assertSessionHasNoErrors();

        $this->get('/')->assertSee('https://wa.me/59170000000', false)->assertSee('Av. Heroínas 100');

        $changed = DB::table('audit_logs')->where('entity_type', 'settings')->pluck('entity_id')->sort()->values()->all();
        $this->assertSame(['pickup_address', 'store_whatsapp'], $changed);
    }
}
