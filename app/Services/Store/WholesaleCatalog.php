<?php

namespace App\Services\Store;

use App\Models\Product;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Lo que muestra la página «Compra por mayor», siempre con datos reales. */
final class WholesaleCatalog
{
    public function __construct(private readonly StoreCatalog $catalog) {}

    /**
     * Por categoría, desde cuántas unidades aplica según el precio por unidad.
     *
     * @return Collection<string, Collection<int, object{min_qty: int, low: string, high: string, products: int}>>
     */
    public function tables(): Collection
    {
        return DB::table('categories')
            ->join('products', 'products.category_id', '=', 'categories.id_category')
            ->where('categories.is_active', true)
            ->where('products.is_active', true)
            ->whereNotNull('products.wholesale_min_qty')
            ->groupBy('categories.name', 'products.wholesale_min_qty')
            ->orderBy('categories.name')
            ->orderByDesc('products.wholesale_min_qty')
            ->get([
                'categories.name',
                'products.wholesale_min_qty as min_qty',
                DB::raw('min(products.price_unit_bs) as low'),
                DB::raw('max(products.price_unit_bs) as high'),
                DB::raw('count(*)::int as products'),
            ])
            ->groupBy('name');
    }

    /**
     * Una cuenta de ejemplo: el producto más económico de dos categorías, al
     * mínimo por mayor.
     *
     * @return array{lines: list<array{name: string, quantity: int, total_cents: int}>, total_cents: int, savings_cents: int}|null
     */
    public function example(): ?array
    {
        $products = Product::query()
            ->where('is_active', true)
            ->whereNotNull('wholesale_min_qty')
            ->whereNotNull('category_id')
            ->orderBy('price_unit_bs')
            ->orderBy('item_code')
            ->get()
            ->unique('category_id')
            ->take(2);

        if ($products->isEmpty()) {
            return null;
        }

        $lines = [];
        $total = 0;
        $savings = 0;

        foreach ($products as $product) {
            $quantity = $product->wholesale_min_qty;
            $wholesale = Money::toCents($product->price_wholesale_bs) * $quantity;

            $lines[] = ['name' => $product->name, 'quantity' => $quantity, 'total_cents' => $wholesale];
            $total += $wholesale;
            $savings += Money::toCents($product->price_unit_bs) * $quantity - $wholesale;
        }

        return ['lines' => $lines, 'total_cents' => $total, 'savings_cents' => $savings];
    }

    /**
     * Los más pedidos por mayor según las ventas. Sin ventas todavía, se
     * sugieren los de stock suficiente para un pedido por mayor.
     *
     * @return array{cards: Collection<int, ProductCard>, from_sales: bool}
     */
    public function popular(int $limit = 4): array
    {
        $ids = DB::table('order_items as i')
            ->join('orders as o', 'o.id_order', '=', 'i.order_id')
            ->join('products as p', 'p.id_product', '=', 'i.product_id')
            ->where('i.price_type', 'wholesale')
            ->whereNotIn('o.status', ['cancelled', 'expired', 'returned'])
            ->where('p.is_active', true)
            ->groupBy('i.product_id')
            ->orderByRaw('sum(i.quantity) desc')
            ->orderBy('i.product_id')
            ->limit($limit)
            ->pluck('i.product_id')
            ->all();

        $fromSales = $ids !== [];

        if (! $fromSales) {
            $ids = Product::query()
                ->join('inventory', 'inventory.product_id', '=', 'products.id_product')
                ->where('products.is_active', true)
                ->whereRaw('inventory.stock - inventory.reserved >= products.wholesale_min_qty')
                ->orderBy('products.price_unit_bs')
                ->orderBy('products.item_code')
                ->limit($limit)
                ->pluck('products.id_product')
                ->all();
        }

        $products = $this->catalog->productsById($ids);
        $ordered = collect($ids)->map(fn (string $id) => $products->get($id))->filter()->values();

        return ['cards' => $this->catalog->cards($ordered), 'from_sales' => $fromSales];
    }
}
