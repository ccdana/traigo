<?php

namespace Tests\Feature\Web;

use App\Models\Product;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\LocalPinSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\TeamSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** El catálogo y el equipo reales, y atajos para preparar stock y ofertas. */
abstract class WebTestCase extends TestCase
{
    use RefreshDatabase;

    /** Tomatodos de Messi: Bs 87 por unidad; Bs 82 desde 24. */
    protected const CODE = '8107';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CatalogSeeder::class, TeamSeeder::class, LocalPinSeeder::class, SettingsSeeder::class]);
        // Las pruebas no necesitan haber compilado los estilos.
        $this->withoutVite();
    }

    protected function product(string $code = self::CODE): Product
    {
        return Product::query()->where('item_code', $code)->firstOrFail();
    }

    protected function member(string $code): User
    {
        return User::query()->where('code', $code)->firstOrFail();
    }

    protected function stock(Product $product, int $stock, int $reserved = 0): void
    {
        DB::table('inventory')->where('product_id', $product->getKey())->update([
            'stock' => $stock,
            'reserved' => $reserved,
        ]);
    }

    protected function level(Product $product): object
    {
        return DB::table('inventory')->where('product_id', $product->getKey())->first();
    }

    /** @param  array<string, mixed>  $attributes */
    protected function promotion(Product $product, array $attributes = []): string
    {
        $id = (string) Str::uuid7();
        $authorized = ($attributes['status'] ?? 'approved') === 'approved';

        DB::table('promotions')->insert([
            'id_promotion' => $id,
            'name' => 'Oferta de prueba',
            'discount_type' => 'fixed_price',
            'discount_value' => 85,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
            'status' => 'approved',
            'created_by' => $this->member('JIM-01')->getKey(),
            'authorized_by' => $authorized ? $this->member('DIR-08')->getKey() : null,
            'authorized_at' => $authorized ? now() : null,
            ...$attributes,
        ]);
        DB::table('promotion_products')->insert(['promotion_id' => $id, 'product_id' => $product->getKey()]);

        return $id;
    }
}
