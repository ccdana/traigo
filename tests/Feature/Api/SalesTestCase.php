<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\LocalStockSeeder;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** El catálogo real con el stock de ejemplo, además del equipo. */
abstract class SalesTestCase extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CatalogSeeder::class, LocalStockSeeder::class]);
    }

    protected function product(string $code): Product
    {
        return Product::query()->where('item_code', $code)->firstOrFail();
    }

    /** @return array{stock: int, reserved: int} */
    protected function inventory(string $code): array
    {
        $row = \DB::table('inventory')->where('product_id', $this->product($code)->getKey())->first();

        return ['stock' => $row->stock, 'reserved' => $row->reserved];
    }

    /**
     * Una venta como la manda la app.
     *
     * @param  array<string, int>  $lines  código → cantidad
     * @return array<string, mixed>
     */
    protected function sale(array $lines = ['8107' => 2], string $total = '174.00', array $with = []): array
    {
        return array_replace_recursive([
            'origin' => 'whatsapp',
            'customer' => ['name' => 'Mariela Quispe', 'phone' => '71234567'],
            'delivery' => [
                'type' => 'pickup',
                'address' => null,
                'promised_at' => now()->addHours(3)->toIso8601String(),
            ],
            'payment_method' => 'qr',
            'lines' => collect($lines)
                ->map(fn (int $quantity, string $code) => [
                    'product_id' => $this->product((string) $code)->getKey(),
                    'quantity' => $quantity,
                ])
                ->values()
                ->all(),
            'expected_total' => $total,
        ], $with);
    }

    protected function sell(array $sale, string $seller = 'ALI-04', ?string $key = null): TestResponse
    {
        return $this->as($this->tokenFor($seller))->postJson('/api/v1/orders', $sale, [
            'Idempotency-Key' => $key ?? (string) Str::uuid(),
        ]);
    }

    protected function api(string $code): static
    {
        return $this->as($this->tokenFor($code));
    }
}
