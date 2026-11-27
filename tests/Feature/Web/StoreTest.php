<?php

namespace Tests\Feature\Web;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

class StoreTest extends WebTestCase
{
    #[Test]
    public function el_presupuesto_desde_250_es_igual_con_y_sin_sesion(): void
    {
        DB::table('products')->where('id_product', $this->product()->getKey())->update(['price_unit_bs' => 250]);
        $guest = $this->get('/?presupuesto=250')->assertOk()->assertSee('Bs 250 en adelante')
            ->assertViewHas('gifts', fn ($gifts) => $gifts->isNotEmpty() && $gifts->every(fn ($gift) => $gift->priceCents >= 25000));
        $this->actingAs(\App\Models\User::factory()->customer()->create(), 'customer');
        $customer = $this->get('/?presupuesto=250')->assertOk();
        $this->assertSame($guest->viewData('gifts')->pluck('id')->all(), $customer->viewData('gifts')->pluck('id')->all());
    }

    #[Test]
    public function filtros_y_orden_usan_el_precio_con_oferta(): void
    {
        $product = $this->product();
        DB::table('products')->where('id_product', $product->getKey())->update(['price_unit_bs' => 160]);
        $this->promotion($product, ['discount_value' => 140]);
        $this->get('/categoria/tomatodos?precio=50-150&orden=precio')
            ->assertOk()->assertViewHas('products', function ($page) use ($product) {
                $cards = $page->getCollection();
                return $cards->contains('id', $product->getKey())
                    && $cards->pluck('priceCents')->all() === $cards->pluck('priceCents')->sort()->values()->all();
            });
    }

    #[Test]
    public function la_portada_muestra_las_categorias_el_calendario_y_los_regalos(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSeeInOrder(['Tomatodos', 'Macetas', 'Juguetes'])
            ->assertSee('Una oferta por día')
            ->assertSee('¿Qué regalo?')
            ->assertViewHas('doors', fn (array $doors) => count($doors) === 24)
            ->assertViewHas('gifts', fn ($gifts) => $gifts->count() === 12);
    }

    #[Test]
    public function el_presupuesto_filtra_los_regalos_y_un_valor_raro_se_ignora(): void
    {
        $this->get('/?presupuesto=50')
            ->assertViewHas('gifts', fn ($gifts) => $gifts->isNotEmpty() && $gifts->every(fn ($gift) => $gift->priceCents <= 5000));

        $this->get('/?presupuesto=abc&para[]=x')->assertOk()->assertViewHas('budget', null);
    }

    #[Test]
    public function la_tienda_nunca_muestra_el_costo_ni_la_configuracion_interna(): void
    {
        DB::table('products')->where('id_product', $this->product()->getKey())->update(['cost_bs' => '41.37']);
        DB::table('settings')->where('key', 'commission_pay_note')->update(['value' => json_encode('NOTA INTERNA')]);

        $this->get('/producto/'.$this->product()->slug)->assertOk()->assertDontSee('41.37')->assertDontSee('41,37');
        $this->get('/')->assertDontSee('NOTA INTERNA');
    }

    #[Test]
    public function la_categoria_filtra_por_precio_y_ordena(): void
    {
        $this->get('/categoria/macetas?precio=hasta-50&orden=precio-desc')
            ->assertOk()
            ->assertViewHas('products', function ($products) {
                $prices = $products->getCollection()->pluck('priceCents');

                return $prices->isNotEmpty() && $prices->max() <= 5000
                    && $prices->all() === $prices->sortDesc()->values()->all();
            });

        $this->get('/categoria/macetas?precio=cualquiera')->assertOk()->assertViewHas('range', null);
        $this->get('/categoria/no-existe')->assertNotFound();
    }

    #[Test]
    public function la_ficha_explica_el_precio_por_mayor_y_avisa_si_esta_agotado(): void
    {
        $product = $this->product();

        $this->get('/producto/'.$product->slug)
            ->assertOk()
            ->assertSee('1 a 23 unidades')
            ->assertSee('Desde 24 del mismo código')
            ->assertSee('Bs 82 c/u')
            ->assertSee('Agotado');

        $this->stock($product, 10);

        $this->get('/producto/'.$product->slug)->assertSee('Agregar al carrito');
    }

    #[Test]
    public function lo_que_ya_no_se_vende_no_tiene_pagina(): void
    {
        $product = $this->product();
        DB::table('products')->where('id_product', $product->getKey())->update(['is_active' => false]);

        $this->get('/producto/'.$product->slug)->assertNotFound();
    }

    #[Test]
    public function el_calendario_no_revela_una_oferta_antes_de_abrirla(): void
    {
        $this->promotion($this->product(), [
            'name' => 'Sorpresa secreta',
            'calendar_day' => 5,
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(3),
        ]);
        $this->promotion($this->product('8109'), ['name' => 'Puerta abierta', 'calendar_day' => 4]);

        $this->get('/')
            ->assertSee('Puerta abierta')
            ->assertDontSee('Sorpresa secreta');
    }

    #[Test]
    public function una_oferta_autorizada_se_ve_con_el_precio_anterior_tachado(): void
    {
        $this->promotion($this->product());

        $this->get('/producto/'.$this->product()->slug)->assertSee('Bs 85')->assertSee('<s class="text-muted">Bs 87</s>', false);
    }

    #[Test]
    public function el_encabezado_muestra_whatsapp_solo_cuando_esta_definido(): void
    {
        $this->get('/')->assertDontSee('wa.me');

        DB::table('settings')->where('key', 'store_whatsapp')->update(['value' => json_encode('+591 700-00000')]);
        $this->app->forgetScopedInstances();

        $this->get('/')->assertSee('https://wa.me/59170000000', false);
    }
}
