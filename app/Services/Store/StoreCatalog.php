<?php

namespace App\Services\Store;

use App\Models\Product;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Las consultas de la tienda pública: solo lo que está a la venta. */
final class StoreCatalog
{
    public const PER_PAGE = 24;

    /** Filtro de precio por unidad: [etiqueta, desde, hasta]. */
    public const PRICE_RANGES = [
        'hasta-50' => ['Hasta Bs 50', null, 50],
        '50-150' => ['Bs 50 a 150', 50, 150],
        '150-250' => ['Bs 150 a 250', 150, 250],
        'mas-250' => ['Bs 250 en adelante', 250, null],
    ];

    public const SORTS = [
        'precio' => 'Menor precio',
        'precio-desc' => 'Mayor precio',
        'nombre' => 'Nombre',
    ];

    public const GIFT_BUDGETS = [50, 150, 250];

    private const PRICE = 'least(products.price_unit_bs, coalesce(store_offer.price_bs, products.price_unit_bs))';

    public const GIFT_AUDIENCES = [
        'kids' => 'Niños',
        'home' => 'Casa',
        'school_sport' => 'Colegio y deporte',
    ];

    /** @return Collection<int, object{id_category: string, name: string, slug: string, products_count: int}> */
    public function categories(): Collection
    {
        return DB::table('categories')
            ->join('products', 'products.category_id', '=', 'categories.id_category')
            ->where('categories.is_active', true)
            ->where('products.is_active', true)
            ->groupBy('categories.id_category', 'categories.name', 'categories.slug', 'categories.created_at')
            ->orderBy('categories.created_at')
            ->orderByDesc('categories.name')
            ->get([
                'categories.id_category', 'categories.name', 'categories.slug',
                DB::raw('count(*)::int as products_count'),
            ]);
    }

    public function category(string $slug): ?object
    {
        return DB::table('categories')->where('slug', $slug)->where('is_active', true)->first();
    }

    /** @return LengthAwarePaginator<int, ProductCard> */
    public function byCategory(string $categoryId, ?string $range, string $sort): LengthAwarePaginator
    {
        $query = $this->query()->where('products.category_id', $categoryId);

        if ($range !== null) {
            [, $from, $to] = self::PRICE_RANGES[$range];
            $query
                ->when($from !== null, fn (Builder $q) => $q->whereRaw(self::PRICE.($range === 'mas-250' ? ' >= ?' : ' > ?'), [$from]))
                ->when($to !== null, fn (Builder $q) => $q->whereRaw(self::PRICE.' <= ?', [$to]));
        }

        match ($sort) {
            'precio-desc' => $query->orderByRaw(self::PRICE.' desc'),
            'nombre' => $query->orderByRaw(Product::foldSql('products.name')),
            default => $query->orderByRaw(self::PRICE),
        };

        $page = $query->orderBy('products.item_code')->paginate(self::PER_PAGE)->withQueryString();

        return $page->setCollection($this->cards($page->getCollection()));
    }

    /**
     * «¿Qué regalo?»: primero lo que hay en stock, de menor a mayor precio.
     *
     * @return Collection<int, ProductCard>
     */
    public function gifts(?int $budget, ?string $audience, int $limit = 12): Collection
    {
        $products = $this->query()
            ->when($budget !== null, fn (Builder $q) => $q->whereRaw(self::PRICE.($budget === 250 ? ' >= ?' : ' <= ?'), [$budget]))
            ->when($audience !== null, fn (Builder $q) => $q->where('products.gift_audience', $audience))
            ->orderByRaw('(coalesce(inventory.stock, 0) - coalesce(inventory.reserved, 0) > 0) desc')
            ->orderByRaw(self::PRICE)
            ->orderBy('products.item_code')
            ->limit($limit)
            ->get();

        return $this->cards($products);
    }

    /** Los públicos que de verdad tienen productos: sin datos no hay filtro. */
    public function giftAudiences(): array
    {
        $used = Product::query()->where('is_active', true)->whereNotNull('gift_audience')
            ->distinct()->pluck('gift_audience')->all();

        return array_intersect_key(self::GIFT_AUDIENCES, array_flip($used));
    }

    public function find(string $slug): ?Product
    {
        return $this->query()->where('products.slug', $slug)->first();
    }

    /** @return Collection<int, ProductCard> */
    public function related(Product $product, int $limit = 4): Collection
    {
        $products = $this->query()
            ->where('products.category_id', $product->category_id)
            ->where('products.id_product', '<>', $product->getKey())
            ->orderByRaw('(coalesce(inventory.stock, 0) - coalesce(inventory.reserved, 0) > 0) desc')
            ->orderByRaw('abs(products.price_unit_bs - ?)', [$product->price_unit_bs])
            ->orderBy('products.item_code')
            ->limit($limit)
            ->get();

        return $this->cards($products);
    }

    /** @return list<string> */
    public function imagesOf(Product $product): array
    {
        return DB::table('product_images')
            ->where('product_id', $product->getKey())
            ->orderBy('position')
            ->orderBy('created_at')
            ->pluck('url')
            ->all();
    }

    /**
     * Los mínimos por mayor de cada categoría («desde 6 a 24 unidades»).
     *
     * @return Collection<int, object{name: string, min_qty: int, max_qty: int}>
     */
    public function wholesaleMinimums(): Collection
    {
        return DB::table('categories')
            ->join('products', 'products.category_id', '=', 'categories.id_category')
            ->where('categories.is_active', true)
            ->where('products.is_active', true)
            ->whereNotNull('products.wholesale_min_qty')
            ->groupBy('categories.name')
            ->orderBy('categories.name')
            ->get([
                'categories.name',
                DB::raw('min(products.wholesale_min_qty) as min_qty'),
                DB::raw('max(products.wholesale_min_qty) as max_qty'),
            ]);
    }

    /**
     * @param  list<string>  $ids
     * @return Collection<string, Product> por id; lo que ya no se vende no viene
     */
    public function productsById(array $ids): Collection
    {
        return $this->query()->whereIn('products.id_product', $ids)->get()->keyBy('id_product');
    }

    /**
     * @param  Collection<int, Product>  $products  cargados con las consultas de esta clase
     * @return Collection<int, ProductCard>
     */
    public function cards(Collection $products): Collection
    {
        $promotions = StorePricing::activePromotions($products->pluck('id_product')->all());

        return $products->map(
            fn (Product $product) => $this->card($product, $promotions->get($product->getKey(), collect())),
        )->values();
    }

    /** @param  Collection<int, object>  $promotions */
    public function card(Product $product, Collection $promotions): ProductCard
    {
        $price = StorePricing::price($product, 1, $promotions);

        return new ProductCard(
            id: $product->getKey(),
            slug: $product->slug,
            itemCode: $product->item_code,
            name: $product->name,
            categoryName: $product->category_name ?? 'Sin categoría',
            categorySlug: $product->category_slug,
            priceCents: $price['cents'],
            listCents: $price['list_cents'],
            wholesaleCents: $product->price_wholesale_bs === null ? null : Money::toCents($product->price_wholesale_bs),
            wholesaleMinQty: $product->wholesale_min_qty,
            available: max(0, (int) $product->stock - (int) $product->reserved),
            imageUrl: $product->image_url,
        );
    }

    /** @return Builder<Product> */
    private function query(): Builder
    {
        return Product::query()
            ->forCatalog()
            ->leftJoinLateral(StorePricing::catalogOffers(), 'store_offer')
            ->addSelect('categories.slug as category_slug')
            ->where('products.is_active', true);
    }
}
