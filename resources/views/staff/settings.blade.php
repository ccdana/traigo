<x-layouts.staff title="Configuración">
    <h1 class="text-3xl">Configuración de la tienda</h1>
    <p class="mt-1 text-sm text-muted">Lo que dejes vacío no se muestra en la tienda. Cada cambio queda en el historial.</p>
    <x-flash class="mt-4" />

    <div class="mt-4 grid items-start gap-5 lg:grid-cols-2">
        <form method="POST" action="{{ route('staff.settings.update') }}" class="space-y-4 bg-white p-5">
            @csrf @method('PATCH')
            <h2 class="text-xl">Datos de la tienda</h2>
            <x-field name="store_whatsapp" label="WhatsApp (con código de país)" :value="$values['store_whatsapp'] ?? null" placeholder="+591 70000000" />
            <x-field name="store_tiktok" label="Usuario de TikTok" :value="$values['store_tiktok'] ?? null" />
            <x-field name="pickup_address" label="Dirección del punto de recojo" :value="$values['pickup_address'] ?? null" />
            <x-field name="delivery_fee_note" label="Costo del delivery (texto)" :value="$values['delivery_fee_note'] ?? null" />
            <x-field name="christmas_order_deadline" label="Último día para pedir antes de Nochebuena" type="date" :value="$values['christmas_order_deadline'] ?? null" />
            <x-field name="order_reservation_hours" label="Horas de reserva de un pedido web" type="number" min="1" max="48" required :value="$values['order_reservation_hours'] ?? 2" />
            <button type="submit" class="btn-primary">Guardar</button>
        </form>

        <section class="space-y-4 bg-white p-5" aria-labelledby="qr">
            <h2 id="qr" class="text-xl">QR de pago</h2>
            <p class="text-sm text-muted">Sin QR, la tienda no recibe pedidos en línea.</p>

            @if ($url = $values['payment_qr_url'] ?? null)
                <img src="{{ $url }}" alt="QR de pago publicado" class="max-h-72 w-auto border border-line p-2">
            @else
                <p class="bg-warn-soft p-3 text-sm text-warn">Todavía no hay un QR cargado.</p>
            @endif

            @if (auth()->user()->role->canAuthorize())
                <form method="POST" action="{{ route('staff.settings.qr') }}" enctype="multipart/form-data" data-once class="space-y-3">
                    @csrf
                    <label for="qr-file" class="eyebrow">Imagen del QR (hasta 2 MB)</label>
                    <input id="qr-file" type="file" name="qr" accept="image/jpeg,image/png,image/webp" required class="field">
                    <button type="submit" class="btn-dark">Publicar QR</button>
                </form>
                @if ($url)
                    <form method="POST" action="{{ route('staff.settings.qr.destroy') }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="cursor-pointer text-sm text-danger underline">Quitar el QR y pausar los pedidos en línea</button>
                    </form>
                @endif
            @else
                <p class="text-sm text-muted">Solo Dirección puede cambiar el QR: define a qué cuenta llega el dinero.</p>
            @endif
        </section>
    </div>
</x-layouts.staff>
