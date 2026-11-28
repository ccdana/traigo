<?php

namespace App\Services\Store;

use App\Models\Product;
use App\Services\Sales\Pricing;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Precio de la tienda web: la misma regla por unidad o por mayor de la app
 * ({@see Pricing}) más las promociones autorizadas, que son solo de la web.
 * Ninguna oferta baja del precio por mayor.
 */
final class StorePricing
{
    /** Precio de una unidad para filtrar antes de paginar; misma fórmula que price(). */
    public static function catalogOffers(): \Illuminate\Database\Query\Builder
    {
        return DB::table('promotion_products as pp')
            ->join('promotions as pr', 'pr.id_promotion', '=', 'pp.promotion_id')
            ->whereColumn('pp.product_id', 'products.id_product')
            ->where('pr.status', 'approved')->where('pr.is_active', true)
            ->where('pr.starts_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('pr.ends_at')->orWhere('pr.ends_at', '>', now()))
            ->selectRaw("min(greatest(case pr.discount_type
                when 'percentage' then round(products.price_unit_bs * (100 - pr.discount_value) / 100, 2)
                when 'fixed_amount' then products.price_unit_bs - pr.discount_value
                when 'fixed_price' then pr.discount_value
                end, coalesce(products.price_wholesale_bs, 0.01))) as price_bs");
    }

    /** Lo que queda de un precio al aplicarle un descuento, en centavos. */
    public static function discounted(string $discountType, string|int|float $value, int $cents): int
    {
        return match ($discountType) {
            'percentage' => (int) round($cents * (100 - (float) $value) / 100),
            'fixed_amount' => $cents - (int) round((float) $value * 100),
            'fixed_price' => (int) round((float) $value * 100),
        };
    }

    /**
     * Las promociones que valen ahora para esos productos.
     *
     * @param  list<string>  $productIds
     * @return Collection<string, Collection<int, object>> por producto
     */
    public static function activePromotions(array $productIds): Collection
    {
        return DB::table('promotion_products as pp')
            ->join('promotions as pr', 'pr.id_promotion', '=', 'pp.promotion_id')
            ->whereIn('pp.product_id', $productIds)
            ->where('pr.status', 'approved')
            ->where('pr.is_active', true)
            ->where('pr.starts_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('pr.ends_at')->orWhere('pr.ends_at', '>', now()))
            ->get([
                'pp.product_id', 'pr.id_promotion', 'pr.discount_type',
                'pr.discount_value', 'pr.applies_to_wholesale',
            ])
            ->groupBy('product_id');
    }

    /**
     * El precio por unidad que paga un cliente al llevar esa cantidad. Si hay
     * varias promociones, gana la que más le conviene.
     *
     * @param  Collection<int, object>  $promotions  las vigentes de este producto
     * @return array{type: 'unit'|'wholesale', cents: int, list_cents: int|null, promotion_id: string|null}
     */
    public static function price(Product $product, int $quantity, Collection $promotions): array
    {
        $base = Pricing::unitPrice($product, $quantity);
        $floor = $product->price_wholesale_bs === null ? 1 : Money::toCents($product->price_wholesale_bs);

        $best = ['type' => $base['type'], 'cents' => $base['cents'], 'list_cents' => null, 'promotion_id' => null];

        foreach ($promotions as $promotion) {
            if ($base['type'] === 'wholesale' && ! $promotion->applies_to_wholesale) {
                continue;
            }

            $cents = max(
                self::discounted($promotion->discount_type, $promotion->discount_value, $base['cents']),
                $floor,
            );

            if ($cents < $best['cents']) {
                $best = [
                    'type' => $base['type'],
                    'cents' => $cents,
                    'list_cents' => $base['cents'],
                    'promotion_id' => $promotion->id_promotion,
                ];
            }
        }

        return $best;
    }
}
