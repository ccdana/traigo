<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\Store\Bag;
use App\Services\Store\StoreCatalog;
use App\Services\Store\StoreSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** El carrito: agregar, cambiar cantidades y quitar. Todavía no aparta stock. */
class BagController extends Controller
{
    public function __construct(
        private readonly Bag $bag,
        private readonly StoreCatalog $catalog,
    ) {}

    public function show(StoreSettings $settings): View
    {
        $contents = $this->bag->contents();

        $message = "Hola Traigo, quiero hacer este pedido:\n".$contents['lines']
            ->map(fn (array $line) => "- {$line['quantity']} × {$line['product']->name} ({$line['product']->itemCode})")
            ->implode("\n");

        return view('store.bag', [
            ...$contents,
            'orderUrl' => $contents['lines']->isEmpty() ? null : $settings->whatsappUrl($message),
            'canCheckout' => $settings->onlinePaymentEnabled(),
            'hours' => $settings->reservationHours(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'uuid'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.Bag::MAX_QUANTITY],
        ], [], ['quantity' => 'la cantidad']);

        $productId = $data['product_id'];

        if ($this->bag->isFull($productId)) {
            return back()->withErrors(['quantity' => 'Tu carrito está lleno. Quita un producto para agregar otro.']);
        }

        return $this->save($productId, $this->bag->quantityOf($productId) + (int) $data['quantity'], 'Agregado a tu carrito.');
    }

    public function update(Request $request, string $product): RedirectResponse
    {
        $data = $request->validate(
            ['quantity' => ['required', 'integer', 'min:0', 'max:'.Bag::MAX_QUANTITY]],
            [],
            ['quantity' => 'la cantidad'],
        );

        // Solo se cambia lo que ya está en el carrito.
        if ($this->bag->quantityOf($product) === 0) {
            return to_route('store.bag.show');
        }

        return $this->save($product, (int) $data['quantity'], null);
    }

    public function destroy(string $product): RedirectResponse
    {
        $this->bag->remove($product);

        return back();
    }

    /** Nunca deja pedir más de lo que hay disponible. */
    private function save(string $productId, int $quantity, ?string $success): RedirectResponse
    {
        if ($quantity === 0) {
            $this->bag->remove($productId);

            return back();
        }

        $product = $this->catalog->productsById([$productId])->get($productId);

        if (! $product) {
            $this->bag->remove($productId);

            return back()->withErrors(['quantity' => 'Ese producto ya no está a la venta.']);
        }

        $available = $this->catalog->card($product, collect())->available;

        if ($available === 0) {
            $this->bag->remove($productId);

            return back()->withErrors(['quantity' => "{$product->name} se agotó."]);
        }

        $this->bag->put($productId, min($quantity, $available));

        if ($quantity > $available) {
            return back()->with('status', "Solo quedan {$available} de {$product->name}: dejamos esa cantidad en tu carrito.");
        }

        return back()->with('status', $success);
    }
}
