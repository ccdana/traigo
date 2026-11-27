@use('App\Support\Money')
@use('App\Services\Store\DeliveryLocations')

<x-layouts.staff title="Estadísticas">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div><h1 class="text-3xl sm:text-4xl">Así va Traigo</h1><p class="mt-2 text-sm text-muted">Cobros, pedidos e inventario para tomar decisiones.</p></div>
        <a href="{{ route('store.home') }}" class="btn-soft">Ver tienda</a>
    </div>
    <x-flash class="mt-4" />
    <form method="GET" class="mt-6 flex flex-wrap items-end gap-3 border-b-2 border-ink pb-5">
        <div><label for="from" class="block text-sm">Desde</label><input class="field" type="date" id="from" name="from" value="{{ $from->toDateString() }}" max="{{ today()->toDateString() }}" required></div>
        <div><label for="until" class="block text-sm">Hasta</label><input class="field" type="date" id="until" name="until" value="{{ $until->toDateString() }}" max="{{ today()->toDateString() }}" required></div>
        <div><label for="channel" class="block text-sm">Canal</label><select id="channel" name="channel" class="field">
            @foreach (['all' => 'Todos los canales', 'web' => 'Tienda web', 'team' => 'Ventas del equipo'] as $value => $label)
                <option value="{{ $value }}" @selected($channel === $value)>{{ $label }}</option>
            @endforeach
        </select></div>
        <button type="submit" class="btn-primary">Ver período</button>
    </form>

    <dl class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([['Cobrado y confirmado', Money::display($collectedCents), $paidCount.' pedidos con pago confirmado'], ['Pedidos recibidos', $ordersCount, $cancelledCount.' anulados, devueltos o vencidos'], ['Unidades entregadas', $deliveredUnits, 'Según la fecha de entrega'], ['Pagos por revisar', $pending->review, 'Pendientes ahora, de cualquier fecha']] as [$label, $value, $detail])
            <div class="border-l-4 border-brand bg-white p-5"><dt class="text-sm text-muted">{{ $label }}</dt><dd class="mt-2 font-display text-3xl font-bold tabular-nums">{{ $value }}</dd><dd class="mt-2 text-xs text-muted">{{ $detail }}</dd></div>
        @endforeach
    </dl>

    <div class="mt-8 grid items-start gap-8 lg:grid-cols-[minmax(0,2fr)_minmax(15rem,1fr)]">
        <section class="min-w-0 bg-white p-5" aria-labelledby="daily-title">
            <h2 id="daily-title" class="text-xl">Cobros por día</h2>
            <p class="mt-1 text-xs text-muted">Solo pagos confirmados, según cuándo llegó el dinero. Excluye devoluciones.</p>
            @if ($collectedCents === 0)
                <p class="py-12 text-center text-sm text-muted">Todavía no hay cobros confirmados en este período.</p>
            @else
                <div class="mt-6 overflow-x-auto" tabindex="0" aria-label="Gráfico de cobros diarios; detalle en la tabla inferior">
                    <div class="flex h-44 items-end gap-1 border-b border-line" style="min-width: {{ max(300, count($days) * 12) }}px" aria-hidden="true">
                        @foreach ($days as $day)
                            <div class="flex-1 bg-brand hover:bg-cyan" style="height: {{ $day['cents'] > 0 ? max(2, round($day['cents'] / $peakCents * 100)) : 0 }}%" title="{{ $day['label'] }}: {{ Money::display($day['cents']) }}"></div>
                        @endforeach
                    </div>
                </div>
                <p class="mt-2 flex justify-between text-xs text-muted"><span>{{ $from->format('d/m/Y') }}</span><span>{{ $until->format('d/m/Y') }}</span></p>
                <details class="mt-4 text-sm"><summary class="cursor-pointer text-brand">Ver importes por día</summary><div class="mt-2 max-h-64 overflow-auto"><table class="w-full text-left"><caption class="sr-only">Cobros confirmados por fecha</caption><thead><tr><th>Fecha</th><th class="text-right">Cobrado</th></tr></thead><tbody>
                    @foreach ($days as $day)<tr class="border-t border-line"><td class="py-2">{{ $day['date'] }}</td><td class="text-right">{{ Money::display($day['cents']) }}</td></tr>@endforeach
                </tbody></table></div></details>
            @endif
        </section>
        <section class="border-t-2 border-ink pt-4" aria-labelledby="stock-title">
            <h2 id="stock-title" class="text-xl">Inventario ahora</h2>
            <p class="mt-1 text-xs text-muted">Stock global de todos los canales; no depende del período.</p>
            <dl class="mt-4 divide-y divide-line text-sm">
                @foreach (['En almacén' => $stock->stock, 'Reservadas' => $stock->reserved, 'Disponibles' => $stock->available, 'Productos agotados' => $stock->out_of_stock, 'Productos con stock bajo' => $stock->low_stock] as $label => $value)
                    <div class="flex justify-between py-2"><dt>{{ $label }}</dt><dd class="font-mono font-semibold">{{ $value }}</dd></div>
                @endforeach
            </dl>
            <a href="{{ route('staff.inventory.index') }}" class="btn-dark mt-4 w-full">Ver inventario</a>
            <a href="{{ route('staff.promotions.index') }}" class="btn-soft mt-2 w-full">Ver ofertas</a>
        </section>
    </div>

    <section class="mt-8" aria-labelledby="top-title">
        <h2 id="top-title" class="text-xl">Productos más entregados</h2>
        <div class="mt-4 overflow-x-auto bg-white"><table class="w-full text-left text-sm"><caption class="sr-only">Productos entregados durante el período seleccionado</caption><thead class="border-b-2 border-ink"><tr><th class="p-3">Producto</th><th class="p-3 text-right">Unidades</th><th class="p-3 text-right">Importe</th></tr></thead><tbody>
            @forelse ($topProducts as $product)<tr class="border-b border-line"><td class="p-3">{{ $product->name }} <span class="text-xs text-muted">{{ $product->item_code }}</span></td><td class="p-3 text-right">{{ $product->units }}</td><td class="p-3 text-right">{{ Money::display(Money::toCents($product->amount)) }}</td></tr>
            @empty<tr><td colspan="3" class="p-6 text-muted">No hay entregas en este período.</td></tr>@endforelse
        </tbody></table></div>
    </section>

    <section class="mt-8" aria-labelledby="deliveries-title">
        <h2 id="deliveries-title" class="text-xl">Delivery por entregar</h2>
        <p class="mt-1 text-sm text-muted">Pedidos pagados o listos del canal seleccionado, de cualquier fecha. Hasta 20, empezando por el más antiguo.</p>
        <ul class="mt-4 grid gap-3 md:grid-cols-2">
            @forelse ($deliveries as $delivery)
                <li class="bg-white p-4"><p class="font-semibold">{{ $delivery->order_number }} · {{ $delivery->customer_name }}</p><p class="mt-1 text-sm">{{ $delivery->address ?? $delivery->shipping_address }}</p>
                    @if ($delivery->reference)<p class="mt-1 text-sm text-muted">Referencia: {{ $delivery->reference }}</p>@endif
                    @if ($delivery->latitude !== null)<a href="{{ DeliveryLocations::mapUrl($delivery->latitude, $delivery->longitude) }}" target="_blank" rel="noopener noreferrer" class="mt-3 inline-block text-sm text-brand underline">Abrir punto de entrega</a>@endif
                </li>
            @empty<li class="text-sm text-muted">No hay delivery pendiente de entrega.</li>@endforelse
        </ul>
    </section>
</x-layouts.staff>
