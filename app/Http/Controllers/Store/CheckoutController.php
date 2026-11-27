<?php

namespace App\Http\Controllers\Store;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutRequest;
use App\Models\Order;
use App\Services\Store\Bag;
use App\Services\Store\ExpireWebOrders;
use App\Services\Store\PlaceWebOrder;
use App\Services\Store\StoreSettings;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** «Confirma tu pedido»: aquí es donde se aparta el stock. */
class CheckoutController extends Controller
{
    public function __construct(
        private readonly Bag $bag,
        private readonly StoreSettings $settings,
    ) {}

    public function create(Request $request): View|RedirectResponse
    {
        $contents = $this->bag->contents();

        if ($contents['lines']->isEmpty() || $contents['has_shortage'] || ! $this->settings->onlinePaymentEnabled()) {
            return to_route('store.bag.show');
        }

        $customer = $request->user();

        return view('store.checkout', [
            ...$contents,
            'account' => $customer,
            // La dirección de su último delivery, para no escribirla otra vez.
            'lastAddress' => Order::query()
                ->where('customer_id', $customer->getKey())
                ->whereNotNull('shipping_address')
                ->orderByDesc('created_at')
                ->value('shipping_address'),
            'expectedTotal' => Money::format($contents['subtotal_cents']),
            'hours' => $this->settings->reservationHours(),
            'deliveryNote' => $this->settings->get('delivery_fee_note'),
        ]);
    }

    public function store(CheckoutRequest $request, PlaceWebOrder $orders, ExpireWebOrders $expiry): RedirectResponse
    {
        // Sin un QR cargado no hay cómo pagar: no se aparta nada.
        if (! $this->settings->onlinePaymentEnabled()) {
            return to_route('store.bag.show');
        }

        // Primero se libera lo vencido, por si la tarea programada no corrió.
        $expiry->run();

        try {
            $order = $orders->place(
                $request->user(),
                $this->bag->items(),
                $request->delivery(),
                Money::toCents($request->validated('expected_total')),
                Str::lower($request->validated('client_uuid')),
            );
        } catch (ApiException $e) {
            // Si cambió el precio basta con revisar el total; lo demás se
            // corrige en la bolsa.
            $target = in_array($e->errorCode, ['price_changed', 'too_many_open_orders'], true)
                ? to_route('store.checkout.create')->withInput()
                : to_route('store.bag.show');

            return $target->withErrors(['order' => $e->getMessage()]);
        }

        $this->bag->clear();

        return to_route('store.order.show', $order->public_token);
    }
}
