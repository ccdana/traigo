<?php

namespace App\Services\Store;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * «Una oferta por día»: las 24 puertas de diciembre. Lo que todavía no abre no
 * revela su producto.
 */
final class GiftCalendar
{
    public const DOORS = 24;

    public function __construct(private readonly StoreCatalog $catalog) {}

    /**
     * @return list<array{day: int, state: 'empty'|'upcoming'|'open'|'ended', card: ProductCard|null, title: string|null}>
     */
    public function doors(): array
    {
        $promotions = DB::table('promotions')
            ->whereNotNull('calendar_day')
            ->where('status', 'approved')
            ->where('is_active', true)
            ->get(['id_promotion', 'calendar_day', 'name', 'starts_at', 'ends_at'])
            ->keyBy('calendar_day');

        $productIds = DB::table('promotion_products')
            ->whereIn('promotion_id', $promotions->pluck('id_promotion')->all())
            ->orderBy('product_id')
            ->get(['promotion_id', 'product_id'])
            ->groupBy('promotion_id')
            ->map(fn ($rows) => $rows->first()->product_id);

        $cards = $this->catalog
            ->cards($this->catalog->productsById($productIds->values()->all())->values())
            ->keyBy('id');

        return array_map(function (int $day) use ($promotions, $productIds, $cards): array {
            $promotion = $promotions->get($day);
            $state = match (true) {
                $promotion === null => 'empty',
                Carbon::parse($promotion->starts_at)->isFuture() => 'upcoming',
                Carbon::parse($promotion->ends_at)->isPast() => 'ended',
                default => 'open',
            };

            return [
                'day' => $day,
                'state' => $state,
                'card' => $state === 'open' ? $cards->get($productIds->get($promotion->id_promotion)) : null,
                'title' => $state === 'open' ? $promotion->name : null,
            ];
        }, range(1, self::DOORS));
    }
}
