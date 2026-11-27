<?php

namespace Tests\Feature\Web;

use App\Services\Store\StorePricing;
use PHPUnit\Framework\Attributes\Test;

class StorePricingTest extends WebTestCase
{
    /** @return array{type: string, cents: int, list_cents: int|null, promotion_id: string|null} */
    private function priceFor(int $quantity): array
    {
        $product = $this->product();

        return StorePricing::price(
            $product,
            $quantity,
            StorePricing::activePromotions([$product->getKey()])->get($product->getKey(), collect()),
        );
    }

    #[Test]
    public function sin_ofertas_vale_la_regla_por_unidad_o_por_mayor(): void
    {
        $this->assertSame(8700, $this->priceFor(23)['cents']);
        $this->assertSame(8200, $this->priceFor(24)['cents']);
        $this->assertSame('wholesale', $this->priceFor(24)['type']);
    }

    #[Test]
    public function ninguna_oferta_baja_del_precio_por_mayor(): void
    {
        $this->promotion($this->product(), ['discount_type' => 'percentage', 'discount_value' => 50]);

        $this->assertSame(8200, $this->priceFor(1)['cents']);
        $this->assertSame(8700, $this->priceFor(1)['list_cents']);
    }

    #[Test]
    public function entre_varias_ofertas_gana_la_mejor_y_no_se_suma_al_precio_por_mayor(): void
    {
        $this->promotion($this->product(), ['discount_type' => 'fixed_amount', 'discount_value' => 1]);
        $best = $this->promotion($this->product(), ['discount_type' => 'fixed_price', 'discount_value' => 84]);

        $this->assertSame(['type' => 'unit', 'cents' => 8400, 'list_cents' => 8700, 'promotion_id' => $best], $this->priceFor(1));
        $this->assertNull($this->priceFor(24)['promotion_id']);
    }

    #[Test]
    public function no_cuentan_las_ofertas_sin_autorizar_futuras_o_vencidas(): void
    {
        $this->promotion($this->product(), ['status' => 'pending']);
        $this->promotion($this->product(), ['starts_at' => now()->addHour()]);
        $this->promotion($this->product(), ['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
        $this->promotion($this->product(), ['is_active' => false]);

        $this->assertSame(8700, $this->priceFor(1)['cents']);
    }
}
