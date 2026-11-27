<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\Media\ImageStorage;
use App\Services\Store\SettingsEditor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Los datos de la tienda. El QR de pago solo lo cambia Dirección. */
class SettingsController extends Controller
{
    public function __construct(private readonly SettingsEditor $settings) {}

    public function edit(): View
    {
        return view('staff.settings', ['values' => $this->settings->all()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'store_whatsapp' => ['nullable', 'string', 'regex:/^\+?[\d\s\-]{7,20}$/'],
            'store_tiktok' => ['nullable', 'string', 'max:40'],
            'pickup_address' => ['nullable', 'string', 'max:200'],
            'delivery_fee_note' => ['nullable', 'string', 'max:120'],
            'christmas_order_deadline' => ['nullable', 'date_format:Y-m-d'],
            'order_reservation_hours' => ['required', 'integer', 'between:1,48'],
        ], ['store_whatsapp.regex' => 'Escribe el WhatsApp con su código de país, por ejemplo +591 70000000.'], [
            'order_reservation_hours' => 'las horas de reserva',
            'christmas_order_deadline' => 'el último día para pedir',
        ]);

        $this->settings->save($request->user(), [
            ...$data,
            'order_reservation_hours' => (int) $data['order_reservation_hours'],
        ]);

        return back()->with('status', 'Configuración guardada.');
    }

    /**
     * Quien cambie el QR redirige el dinero: pide el rol de Dirección (con su
     * segundo paso) y queda en el historial.
     */
    public function paymentQr(Request $request, ImageStorage $storage): RedirectResponse
    {
        abort_unless($request->user()->role->canAuthorize(), 403);

        $request->validate(
            ['qr' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048']],
            [],
            ['qr' => 'la imagen del QR'],
        );

        // La anterior no se borra: queda como respaldo de lo que estuvo publicado.
        $this->settings->save($request->user(), [
            'payment_qr_url' => $storage->store($request->file('qr'), 'qr')['url'],
        ]);

        return back()->with('status', 'QR actualizado. Pruébalo escaneándolo antes de recibir pedidos.');
    }

    public function removePaymentQr(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role->canAuthorize(), 403);

        $this->settings->save($request->user(), ['payment_qr_url' => null]);

        return back()->with('status', 'QR quitado: la tienda dejó de recibir pedidos en línea.');
    }
}
