<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductRequest;
use App\Models\Product;
use App\Services\Catalog\ProductEditor;
use App\Services\Catalog\ProductImages;
use App\Services\Control\PriceChanges;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** El catálogo en el panel: alta, datos, fotos, publicación y precios. */
class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $search = $request->query('buscar');
        $search = is_string($search) ? Str::limit(trim($search), 80, '') : null;
        $sale = in_array($request->query('venta'), ['si', 'no'], true) ? $request->query('venta') : null;

        return view('staff.products.index', [
            'search' => $search,
            'sale' => $sale,
            'products' => Product::query()
                ->forCatalog()
                ->addSelect(DB::raw('(select count(*) from product_images where product_images.product_id = products.id_product) as images_count'))
                ->when(filled($search), fn ($query) => $query->matching($search))
                ->when($sale !== null, fn ($query) => $query->where('products.is_active', $sale === 'si'))
                ->orderByRaw(Product::foldSql('products.name'))
                ->orderBy('products.item_code')
                ->paginate(30)
                ->withQueryString(),
        ]);
    }

    public function create(): View
    {
        return view('staff.products.create', ['categories' => $this->categories()]);
    }

    public function store(ProductRequest $request, ProductEditor $editor): RedirectResponse
    {
        $id = $editor->create($request->user(), $request->validated());

        return to_route('staff.products.edit', $id)
            ->with('status', 'Producto creado. Agrega sus fotos; Dirección lo pone a la venta.');
    }

    public function edit(string $product, ProductImages $images): View
    {
        return view('staff.products.edit', [
            'product' => Product::query()->forCatalog()->where('products.id_product', $product)->first() ?? abort(404),
            'categories' => $this->categories(),
            'images' => $images->of($product),
            'sheet' => PriceChanges::sheet($product),
        ]);
    }

    public function update(ProductRequest $request, string $product, ProductEditor $editor): RedirectResponse
    {
        $this->ensureExists($product);
        $editor->updateDetails($product, $request->validated());

        return back()->with('status', 'Datos guardados.');
    }

    public function sale(Request $request, string $product, ProductEditor $editor): RedirectResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean']]);
        $this->ensureExists($product);
        $editor->setActive($request->user(), $product, (bool) $data['active']);

        return back()->with('status', $data['active'] ? 'El producto está a la venta.' : 'El producto salió de la venta.');
    }

    /** Jime propone; vale cuando Dirección lo aprueba. */
    public function proposePrice(Request $request, string $product, PriceChanges $prices): RedirectResponse
    {
        $money = ['nullable', ...ProductRequest::MONEY];

        $data = $request->validate([
            'price_unit' => $money,
            'price_wholesale' => $money,
            'wholesale_min_qty' => ['nullable', 'integer', 'min:2', 'max:100000'],
            'commission_pct' => ['nullable', 'numeric', 'between:0,100', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'cost' => $money,
            'reason' => ['nullable', 'string', 'max:500'],
        ], [], [
            'price_unit' => 'el precio por unidad',
            'price_wholesale' => 'el precio por mayor',
            'wholesale_min_qty' => 'el mínimo por mayor',
            'commission_pct' => 'la comisión',
            'cost' => 'el costo',
        ]);

        $this->ensureExists($product);
        $prices->propose(
            $request->user(),
            $product,
            collect($data)->only(array_keys(PriceChanges::FIELDS))->map(fn ($value) => $value === null ? null : (string) $value)->all(),
            $data['reason'] ?? null,
        );

        return back()->with('status', 'Propuesta enviada a Dirección.');
    }

    private function ensureExists(string $product): void
    {
        abort_unless(DB::table('products')->where('id_product', $product)->exists(), 404);
    }

    private function categories()
    {
        return DB::table('categories')->where('is_active', true)->orderBy('name')->get(['id_category', 'name']);
    }
}
