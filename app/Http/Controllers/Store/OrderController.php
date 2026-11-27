<?php

namespace App\Http\Controllers\Store;

use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Sales\SubmitProof;
use App\Services\Store\ExpireWebOrders;
use App\Services\Store\StoreSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Los pedidos del cliente con sesión: pagar con QR, subir el comprobante y
 * seguir el estado. Cada quien ve solo los suyos.
 */
class OrderController extends Controller
{
    public function index(Request $request): View
    {
        return view('store.my-orders', [
            'orders' => $this->own($request)->with('items')->orderByDesc('created_at')->paginate(10),
        ]);
    }

    public function show(Request $request, string $token, ExpireWebOrders $expiry, StoreSettings $settings): Response
    {
        $order = $this->find($request, $token);

        // Si ya pasó la hora, se muestra vencido aunque la tarea no haya corrido.
        $expiry->run($order->getKey());
        $order->refresh()->load(['items', 'currentPayment']);

        $payment = $order->currentPayment;
        $help = $settings->whatsappUrl("Hola Traigo, quiero consultar mi pedido {$order->order_number}.");

        return response()
            ->view('store.order', [
                'order' => $order,
                'rejectReason' => $order->status->isAwaitingPayment() && $payment?->status === PaymentStatus::Rejected
                    ? $payment->reject_reason
                    : null,
                'qrUrl' => $settings->paymentQrUrl(),
                'helpUrl' => $help,
                'pickupAddress' => $settings->get('pickup_address'),
            ])
            // La dirección del pedido no se comparte con otros sitios.
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function proof(Request $request, string $token, SubmitProof $proofs, ExpireWebOrders $expiry): RedirectResponse
    {
        $order = $this->find($request, $token);

        $data = $request->validate([
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'reference' => ['nullable', 'string', 'max:60'],
            'client_uuid' => ['required', 'uuid'],
        ], [
            'proof.required' => 'Elige la foto o captura de tu comprobante.',
            'proof.mimes' => 'El comprobante debe ser una imagen JPG, PNG o WEBP.',
            'proof.max' => 'La imagen no puede pesar más de 5 MB.',
        ], ['proof' => 'el comprobante', 'reference' => 'la referencia']);

        $expiry->run($order->getKey());

        try {
            $proofs->attach(
                fn (): Order => $this->own($request)->lockForUpdate()->findOrFail($order->getKey()),
                $request->user(),
                $request->file('proof'),
                $data['reference'] ?? null,
                Str::lower($data['client_uuid']),
            );
        } catch (ApiException $e) {
            return back()->withInput()->withErrors(['proof' => $e->getMessage()]);
        }

        return to_route('store.order.show', $token)->with('status', 'Recibimos tu comprobante. Lo estamos revisando.');
    }

    /** @return Builder<Order> los pedidos de la tienda de quien tiene la sesión */
    private function own(Request $request): Builder
    {
        return Order::query()->where('origin', 'web')->where('customer_id', $request->user()->getKey());
    }

    /** El de otra persona no existe (404). */
    private function find(Request $request, string $token): Order
    {
        return $this->own($request)->where('public_token', $token)->first() ?? abort(404);
    }
}
