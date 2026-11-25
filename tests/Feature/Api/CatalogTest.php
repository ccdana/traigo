<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use PHPUnit\Framework\Attributes\Test;

class CatalogTest extends SalesTestCase
{
    #[Test]
    public function lista_los_productos_por_nombre_de_a_treinta(): void
    {
        $response = $this->api('ALI-04')->getJson('/api/v1/catalog')
            ->assertOk()
            ->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 3, 'per_page' => 30, 'total' => 62])
            ->assertJsonCount(30, 'data');

        $names = array_map(Product::fold(...), array_column($response->json('data'), 'name'));
        $sorted = $names;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $names);

        $this->api('ALI-04')->getJson('/api/v1/catalog?page=3')->assertJsonCount(2, 'data');
    }

    #[Test]
    public function un_producto_llega_con_precios_stock_y_sin_costo(): void
    {
        $this->product('8107')->forceFill(['cost_bs' => 40])->save();

        $item = $this->api('ALI-04')->getJson('/api/v1/catalog?search=8107')
            ->assertJsonCount(1, 'data')
            ->json('data.0');

        $this->assertSame([
            'id' => $this->product('8107')->getKey(),
            'item_code' => '8107',
            'name' => 'Tomatodos de Messi',
            'category' => 'Tomatodos',
            'price_unit' => '87.00',
            'price_wholesale' => '82.00',
            'wholesale_min_qty' => 24,
            'stock' => 48,
            'reserved' => 0,
            'available' => 48,
            'commission_pct' => null,
            'image_url' => null,
        ], $item);
    }

    #[Test]
    public function busca_sin_importar_mayusculas_ni_tildes(): void
    {
        $this->api('ALI-04')->getJson('/api/v1/catalog?search=DREAM+PEQUENO')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Tomatodos Dream pequeño');

        // Los comodines de SQL se buscan como texto.
        $this->api('ALI-04')->getJson('/api/v1/catalog?search=%25')->assertJsonCount(0, 'data');
    }

    #[Test]
    public function las_categorias_dicen_cuantos_productos_tienen_y_filtran(): void
    {
        $categories = $this->api('ISA-03')->getJson('/api/v1/catalog/categories')->assertOk()->json('data');

        $this->assertSame(['Juguetes', 'Macetas', 'Tomatodos'], array_column($categories, 'name'));
        $this->assertSame(62, array_sum(array_column($categories, 'count')));

        $tomatodos = $categories[2];
        $this->api('ISA-03')->getJson("/api/v1/catalog?category={$tomatodos['id']}")
            ->assertJsonPath('meta.total', $tomatodos['count']);

        $this->api('ISA-03')->getJson('/api/v1/catalog?category=tomatodos')->assertUnprocessable();
    }

    #[Test]
    public function lo_desactivado_no_aparece(): void
    {
        $messi = $this->product('8107');
        $messi->forceFill(['is_active' => false])->save();

        $this->api('ALI-04')->getJson('/api/v1/catalog?search=8107')->assertJsonCount(0, 'data');
        $this->api('ALI-04')->getJson("/api/v1/catalog/{$messi->getKey()}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Ese producto ya no está en el catálogo.');
        $this->api('ALI-04')->getJson('/api/v1/catalog/no-es-un-id')->assertNotFound();
    }

    #[Test]
    public function el_detalle_agrega_la_descripcion_y_las_fotos(): void
    {
        $messi = $this->product('8107');
        foreach ([[2, 'https://img.test/2.jpg'], [0, 'https://img.test/0.jpg']] as [$position, $url]) {
            \DB::table('product_images')->insert([
                'product_id' => $messi->getKey(), 'public_id' => $url, 'url' => $url, 'position' => $position,
            ]);
        }

        $this->api('ALI-04')->getJson("/api/v1/catalog/{$messi->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.images', ['https://img.test/0.jpg', 'https://img.test/2.jpg'])
            ->assertJsonPath('data.image_url', 'https://img.test/0.jpg');
    }

    #[Test]
    public function la_comision_solo_la_ven_quienes_venden_jime_y_direccion(): void
    {
        $this->product('8107')->forceFill(['commission_pct' => 5])->save();

        foreach (['ALI-04' => '5.00', 'KIM-05' => '5.00', 'JIM-01' => '5.00', 'DIR-08' => '5.00',
            'ISA-03' => null, 'AIL-02' => null, 'SIS-06' => null] as $code => $expected) {
            $this->api($code)->getJson('/api/v1/catalog?search=8107')
                ->assertOk()
                ->assertJsonPath('data.0.commission_pct', $expected);
        }
    }
}
