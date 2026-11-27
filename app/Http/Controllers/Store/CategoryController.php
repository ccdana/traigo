<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\Store\StoreCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function __invoke(Request $request, StoreCatalog $catalog, string $slug): View
    {
        $category = $catalog->category($slug) ?? abort(404);

        $range = $request->query('precio');
        $range = is_string($range) && isset(StoreCatalog::PRICE_RANGES[$range]) ? $range : null;

        $sort = $request->query('orden');
        $sort = is_string($sort) && isset(StoreCatalog::SORTS[$sort]) ? $sort : 'precio';

        return view('store.category', [
            'category' => $category,
            'products' => $catalog->byCategory($category->id_category, $range, $sort),
            'range' => $range,
            'sort' => $sort,
        ]);
    }
}
