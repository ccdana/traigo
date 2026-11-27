<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\Store\StoreCatalog;
use App\Services\Store\StorePricing;
use App\Services\Store\StoreSettings;
use Illuminate\Contracts\View\View;

class ProductController extends Controller
{
    public function __invoke(StoreCatalog $catalog, StoreSettings $settings, string $slug): View
    {
        $product = $catalog->find($slug) ?? abort(404);
        $promotions = StorePricing::activePromotions([$product->getKey()])->get($product->getKey(), collect());

        return view('store.product', [
            'product' => $catalog->card($product, $promotions),
            'description' => $product->description,
            'images' => $catalog->imagesOf($product),
            'related' => $catalog->related($product),
            'askUrl' => $settings->whatsappUrl("Hola Traigo, me interesa {$product->name} (código {$product->item_code})."),
        ]);
    }
}
