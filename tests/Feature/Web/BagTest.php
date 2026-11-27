<?php

namespace Tests\Feature\Web;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

class BagTest extends WebTestCase
{
    private function add(string $productId, int $quantity): TestResponse
    {
        return $this->from('/')->post('/carrito', ['product_id' => $productId, 'quantity' => $quantity]);
    }

    #[Test]
    public function agregar_suma_cantidades_y_aplica_el_precio_por_mayor(): void
    {
        $product = $this->product();
        $this->stock($product, 100);

        $this->add($product->getKey(), 20)->assertRedirect('/');
        $this->add($product->getKey(), 4);

        $this->get('/carrito')
            ->assertOk()
            ->assertViewHas('units', 24)
            ->assertViewHas('subtotal_cents', 24 * 8200)
            ->assertSee('Bs 1.968')
            ->assertSee('Carrito · 1');
    }

    #[Test]
    public function el_carrito_dice_cuanto_falta_para_el_precio_por_mayor(): void
    {
        $this->stock($this->product(), 100);
        $this->add($this->product()->getKey(), 2);

        $this->get('/carrito')->assertSee('Te faltan 22.');
    }

    #[Test]
    public function no_deja_pedir_mas_de_lo_disponible_ni_aparta_stock(): void
    {
        $product = $this->product();
        $this->stock($product, 10, 7);

        $this->add($product->getKey(), 50)->assertSessionHas('status');

        $this->get('/carrito')->assertViewHas('units', 3);
        $this->assertSame(7, $this->level($product)->reserved);
        $this->assertSame(10, $this->level($product)->stock);
    }

    #[Test]
    public function un_agotado_o_un_producto_inventado_no_entra(): void
    {
        $this->add($this->product()->getKey(), 1)->assertSessionHasErrors('quantity');
        $this->add((string) Str::uuid(), 1)->assertSessionHasErrors('quantity');
        $this->add('no-es-un-id', 1)->assertSessionHasErrors('product_id');
        $this->add($this->product()->getKey(), 0)->assertSessionHasErrors('quantity');

        $this->get('/carrito')->assertSee('Tu carrito está vacío.');
    }

    #[Test]
    public function se_cambia_la_cantidad_y_con_cero_se_quita(): void
    {
        $product = $this->product();
        $this->stock($product, 100);
        $this->add($product->getKey(), 5);

        $this->patch("/carrito/{$product->getKey()}", ['quantity' => 2]);
        $this->get('/carrito')->assertViewHas('units', 2);

        $this->patch("/carrito/{$product->getKey()}", ['quantity' => 0]);
        $this->get('/carrito')->assertSee('Tu carrito está vacío.');
    }

    #[Test]
    public function lo_que_deja_de_venderse_sale_del_carrito(): void
    {
        $product = $this->product();
        $this->stock($product, 100);
        $this->add($product->getKey(), 1);
        DB::table('products')->where('id_product', $product->getKey())->update(['is_active' => false]);

        $this->get('/carrito')->assertSee('Quitamos de tu carrito')->assertSee('Tu carrito está vacío.');
    }
}
