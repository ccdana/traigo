<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\CatalogProduct;
use App\Models\Product;
use App\Support\Responses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** El catálogo de todo el equipo (también Jime y Dirección, para precios). */
class CatalogController extends Controller
{
    public const PER_PAGE = 30;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:80'],
            'category' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Product::query()->forCatalog()->where('products.is_active', true);

        if (filled($filters['search'] ?? null)) {
            $query->matching($filters['search']);
        }

        if (filled($filters['category'] ?? null)) {
            $query->where('products.category_id', $filters['category']);
        }

        $page = $query
            ->orderByRaw(Product::foldSql('products.name'))
            ->orderBy('products.item_code')
            ->paginate(self::PER_PAGE);

        return Responses::paginated(
            $page,
            fn (Product $product) => CatalogProduct::toArray($product, $request->user()),
        );
    }

    /** Las categorías con productos a la venta y cuántos tiene cada una. */
    public function categories(): JsonResponse
    {
        $categories = DB::table('categories')
            ->join('products', 'products.category_id', '=', 'categories.id_category')
            ->where('categories.is_active', true)
            ->where('products.is_active', true)
            ->groupBy('categories.id_category', 'categories.name')
            ->orderByRaw(Product::foldSql('categories.name'))
            ->get([
                'categories.id_category as id',
                'categories.name',
                DB::raw('count(*)::int as count'),
            ]);

        return response()->json(['data' => $categories]);
    }

    public function show(Request $request, string $product): JsonResponse
    {
        $found = Product::query()
            ->forCatalog()
            ->where('products.is_active', true)
            ->where('products.id_product', $product)
            ->first();

        if (! $found) {
            throw ApiException::notFound('Ese producto ya no está en el catálogo.');
        }

        return response()->json(['data' => CatalogProduct::detail($found, $request->user())]);
    }
}
