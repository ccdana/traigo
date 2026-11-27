<?php

namespace App\Http\Controllers\Staff;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StockMovementRequest;
use App\Models\Product;
use App\Services\Warehouse\RegisterMovement;
use App\Services\Warehouse\StockCard;
use App\Services\Warehouse\StockOverview;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** El panel de inventario: ver el stock, su historial y registrar movimientos. */
class InventoryController extends Controller
{
    public function index(Request $request, StockOverview $overview): View
    {
        $search = $request->query('buscar');
        $search = is_string($search) ? Str::limit(trim($search), 80, '') : null;

        $category = $request->query('categoria');
        $category = is_string($category) && Str::isUuid($category) ? $category : null;

        $status = $request->query('estado');
        $status = is_string($status) && isset(StockOverview::STATUSES[$status]) ? $status : null;

        return view('staff.inventory.index', [
            'products' => $overview->search($search, $category, $status),
            'summary' => $overview->summary(),
            'categories' => DB::table('categories')->orderBy('name')->get(['id_category', 'name']),
            'search' => $search,
            'category' => $category,
            'status' => $status,
        ]);
    }

    public function show(string $product, StockCard $card): View
    {
        return view('staff.inventory.show', [
            'product' => $this->find($product),
            'movements' => $card->of($product),
        ]);
    }

    public function storeMovement(StockMovementRequest $request, string $product, RegisterMovement $movements): RedirectResponse
    {
        $this->find($product);

        try {
            $movements->register(
                $request->user(),
                [
                    'product_id' => $product,
                    'type' => $request->validated('type'),
                    'quantity' => $request->signedQuantity(),
                    'note' => $request->validated('note'),
                ],
                Str::lower($request->validated('client_uuid')),
                'admin',
            );
        } catch (ApiException $e) {
            return back()->withInput()->withErrors(['quantity' => $e->getMessage()]);
        }

        return to_route('staff.inventory.show', $product)->with('status', 'Movimiento registrado.');
    }

    private function find(string $productId): Product
    {
        return Product::query()->forCatalog()->where('products.id_product', $productId)->first() ?? abort(404);
    }
}
