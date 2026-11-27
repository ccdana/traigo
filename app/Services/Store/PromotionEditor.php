<?php

namespace App\Services\Store;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Management\PromotionAuthorization;
use App\Support\AuditNote;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Las ofertas de la tienda: Jime las crea y quedan esperando a Dirección
 * ({@see PromotionAuthorization}). Se retiran, no se
 * borran, para conservar qué oferta tuvo cada venta.
 */
class PromotionEditor
{
    /**
     * @param  array{name: string, discount_type: string, discount_value: string, applies_to_wholesale: bool, calendar_day: ?int, starts_at: \DateTimeInterface, ends_at: ?\DateTimeInterface}  $data  ya validado
     * @param  list<string>  $productIds
     */
    public function create(User $jime, array $data, array $productIds): string
    {
        if ($data['calendar_day'] !== null && (count($productIds) !== 1 || $data['ends_at'] === null)) {
            throw ApiException::invalid('Una puerta del calendario lleva un solo producto y fecha de fin.');
        }

        return DB::transaction(function () use ($jime, $data, $productIds): string {
            $this->ensureNotBelowWholesale($data['discount_type'], $data['discount_value'], $productIds);

            if ($data['calendar_day'] !== null && $this->doorTaken($data['calendar_day'])) {
                throw ApiException::conflict('door_taken', "La puerta {$data['calendar_day']} ya tiene una oferta.");
            }

            $id = (string) Str::uuid7();

            DB::table('promotions')->insert([
                ...$data,
                'id_promotion' => $id,
                'name' => Str::squish($data['name']),
                'status' => 'pending',
                'created_by' => $jime->getKey(),
            ]);
            DB::table('promotion_products')->insert(array_map(
                fn (string $productId) => ['promotion_id' => $id, 'product_id' => $productId],
                $productIds,
            ));
            AuditNote::add($jime, 'promotion_created', 'promotions', $id, ['name' => Str::squish($data['name'])]);

            return $id;
        });
    }

    /** Deja de aplicarse y libera su puerta del calendario. */
    public function withdraw(User $by, string $promotionId): void
    {
        DB::transaction(function () use ($by, $promotionId) {
            $promotion = DB::table('promotions')->where('id_promotion', $promotionId)->lockForUpdate()->first()
                ?? abort(404);

            if (! $promotion->is_active) {
                return;
            }

            DB::table('promotions')->where('id_promotion', $promotionId)->update([
                'is_active' => false,
                'calendar_day' => null,
                'updated_at' => now(),
            ]);
            AuditNote::add($by, 'promotion_withdrawn', 'promotions', $promotionId, ['name' => $promotion->name]);
        });
    }

    /** @param  list<string>  $productIds */
    private function ensureNotBelowWholesale(string $type, string $value, array $productIds): void
    {
        $products = DB::table('products')->whereIn('id_product', $productIds)->orderBy('name')
            ->get(['name', 'price_unit_bs', 'price_wholesale_bs']);

        if ($products->count() !== count($productIds)) {
            throw ApiException::invalid('Un producto elegido ya no existe.');
        }

        foreach ($products as $product) {
            $unit = Money::toCents($product->price_unit_bs);
            $price = StorePricing::discounted($type, $value, $unit);
            $floor = $product->price_wholesale_bs === null ? 1 : Money::toCents($product->price_wholesale_bs);

            if ($price < $floor) {
                throw ApiException::invalid("La oferta dejaría «{$product->name}» por debajo del precio por mayor (".Money::display($floor).').');
            }

            if ($price >= $unit) {
                throw ApiException::invalid("La oferta no rebaja el precio de «{$product->name}».");
            }
        }
    }

    private function doorTaken(int $day): bool
    {
        return DB::table('promotions')->where('calendar_day', $day)->where('status', '<>', 'rejected')->exists();
    }
}
