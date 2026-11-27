<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\Catalog\ProductImages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductImageController extends Controller
{
    public function __construct(private readonly ProductImages $images) {}

    public function store(Request $request, string $product): RedirectResponse
    {
        $request->validate(
            ['photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=200,min_height=200,max_width=6000,max_height=6000']],
            ['photo.dimensions' => 'La foto debe medir entre 200 y 6000 píxeles por lado.'],
            ['photo' => 'la foto'],
        );

        abort_unless(DB::table('products')->where('id_product', $product)->exists(), 404);
        $this->images->add($product, $request->file('photo'));

        return back()->with('status', 'Foto agregada.');
    }

    public function main(string $product, string $image): RedirectResponse
    {
        $this->images->makeMain($product, $image);

        return back()->with('status', 'Foto principal cambiada.');
    }

    public function destroy(string $product, string $image): RedirectResponse
    {
        $this->images->remove($product, $image);

        return back()->with('status', 'Foto borrada.');
    }
}
