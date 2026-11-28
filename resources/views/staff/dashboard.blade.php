@use('App\Support\Money')
@use('App\Services\Store\DeliveryLocations')

<x-layouts.staff title="Estadísticas">
    <header class="dashboard-heading">
        <div>
            <h1>Estadísticas</h1>
            <p>Cobros, pedidos e inventario de TRAIGO.</p>
        </div>
        <a href="{{ route('store.home') }}" class="btn-soft">Ver tienda</a>
    </header>
    <x-flash class="mt-4" />

    <form method="GET" class="dashboard-period" aria-label="Filtros de estadísticas">
        <div>
            <label for="from">Desde</label>
            <input class="field" type="date" id="from" name="from" value="{{ $from->toDateString() }}" max="{{ today()->toDateString() }}" required>
        </div>
        <div>
            <label for="until">Hasta</label>
            <input class="field" type="date" id="until" name="until" value="{{ $until->toDateString() }}" max="{{ today()->toDateString() }}" required>
        </div>
        <div class="dashboard-period-channel">
            <label for="channel">Canal</label>
            <select id="channel" name="channel" class="field">
                @foreach (['all' => 'Todos los canales', 'web' => 'Tienda web', 'team' => 'Ventas del equipo'] as $value => $label)
                    <option value="{{ $value }}" @selected($channel === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn-primary">Aplicar filtros</button>
    </form>

    <div class="dashboard-overview">
        <section class="dashboard-collection" aria-labelledby="collected-title">
            <h2 id="collected-title">Cobrado y confirmado</h2>
            <p class="dashboard-total">{{ Money::display($collectedCents) }}</p>
            <p>{{ $paidCount }} {{ $paidCount === 1 ? 'pedido con pago confirmado' : 'pedidos con pago confirmado' }}</p>
        </section>
        <dl class="dashboard-summary">
            <div><dt>Pedidos recibidos</dt><dd>{{ $ordersCount }}</dd><dd>{{ $cancelledCount }} {{ $cancelledCount === 1 ? 'anulado, devuelto o vencido' : 'anulados, devueltos o vencidos' }}</dd></div>
            <div><dt>Unidades entregadas</dt><dd>{{ $deliveredUnits }}</dd><dd>Según la fecha de entrega</dd></div>
            <div @if ($pending->review > 0) data-needs-review @endif><dt>Pagos por revisar</dt><dd>{{ $pending->review }}</dd><dd>Pendientes de cualquier fecha</dd></div>
        </dl>
    </div>

    <div class="dashboard-columns">
        <section class="dashboard-panel" aria-labelledby="daily-title">
            <header class="dashboard-panel-header">
                <h2 id="daily-title">Cobros por día</h2>
                <p>Pagos confirmados según la fecha de recepción. Excluye devoluciones.</p>
            </header>
            @if ($collectedCents === 0)
                <div class="dashboard-chart-empty">
                    <p>Todavía no hay cobros confirmados en este período.</p>
                </div>
            @else
                <div class="dashboard-chart" aria-hidden="true">
                    <ol class="dashboard-chart-scale">
                        <li>{{ Money::display($peakCents) }}</li>
                        <li>{{ Money::display((int) round($peakCents / 2)) }}</li>
                        <li>Bs 0</li>
                    </ol>
                    <div class="dashboard-chart-scroll">
                        <div class="dashboard-chart-plot" style="--plot-width: {{ max(220, count($days) * 8) }}px">
                            <div class="dashboard-chart-bars">
                                @foreach ($days as $day)
                                    <div style="--bar-height: {{ $day['cents'] > 0 ? max(2, round($day['cents'] / $peakCents * 100)) : 0 }}%" title="{{ $day['label'] }}: {{ Money::display($day['cents']) }}"></div>
                                @endforeach
                            </div>
                            <div class="dashboard-chart-period"><span>{{ $from->format('d/m/Y') }}</span><span>{{ $until->format('d/m/Y') }}</span></div>
                        </div>
                    </div>
                </div>
                <details class="dashboard-chart-detail">
                    <summary>Ver importes por día</summary>
                    <div class="max-h-64 overflow-auto">
                        <table class="dashboard-table">
                            <caption class="sr-only">Cobros confirmados por fecha</caption>
                            <thead><tr><th scope="col">Fecha</th><th scope="col" class="text-right">Cobrado</th></tr></thead>
                            <tbody>
                                @foreach ($days as $day)
                                    <tr><td>{{ $day['date'] }}</td><td class="text-right">{{ Money::display($day['cents']) }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            @endif
        </section>

        <section class="dashboard-panel dashboard-stock" aria-labelledby="stock-title">
            <header class="dashboard-panel-header">
                <h2 id="stock-title">Inventario ahora</h2>
                <p>Stock global de todos los canales.</p>
            </header>
            <dl>
                @foreach (['En almacén' => $stock->stock, 'Reservadas' => $stock->reserved, 'Disponibles' => $stock->available, 'Productos agotados' => $stock->out_of_stock, 'Productos con stock bajo' => $stock->low_stock] as $label => $value)
                    <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                @endforeach
            </dl>
            <div class="dashboard-stock-actions">
                <a href="{{ route('staff.inventory.index') }}" class="btn-dark">Ver inventario</a>
                <a href="{{ route('staff.promotions.index') }}" class="btn-soft">Ver ofertas</a>
            </div>
        </section>
    </div>

    <div class="dashboard-columns">
        <section class="dashboard-panel" aria-labelledby="top-title">
            <header class="dashboard-panel-header">
                <h2 id="top-title">Productos más entregados</h2>
            </header>
            @if ($topProducts->isEmpty())
                <p class="dashboard-empty">No hay entregas en este período.</p>
            @else
                <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Tabla de productos más entregados">
                    <table class="dashboard-table">
                        <caption class="sr-only">Productos entregados durante el período seleccionado</caption>
                        <thead><tr><th scope="col">Producto</th><th scope="col" class="text-right">Unidades</th><th scope="col" class="text-right">Importe</th></tr></thead>
                        <tbody>
                            @foreach ($topProducts as $product)
                                <tr><td>{{ $product->name }} <small>{{ $product->item_code }}</small></td><td class="text-right">{{ $product->units }}</td><td class="text-right">{{ Money::display(Money::toCents($product->amount)) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
        <section class="dashboard-panel" aria-labelledby="deliveries-title">
            <header class="dashboard-panel-header">
                <h2 id="deliveries-title">Delivery por entregar</h2>
                <p>Hasta 20 pedidos pagados o listos del canal seleccionado, desde el más antiguo.</p>
            </header>
            <ul class="dashboard-deliveries">
                @forelse ($deliveries as $delivery)
                    <li>
                        <p><strong>{{ $delivery->order_number }}</strong> {{ $delivery->customer_name }}</p>
                        <p>{{ $delivery->address ?? $delivery->shipping_address }}</p>
                        @if ($delivery->reference)<p>Referencia: {{ $delivery->reference }}</p>@endif
                        @if ($delivery->latitude !== null)<a href="{{ DeliveryLocations::mapUrl($delivery->latitude, $delivery->longitude) }}" target="_blank" rel="noopener noreferrer">Abrir punto de entrega</a>@endif
                    </li>
                @empty
                    <li class="dashboard-empty">No hay delivery pendiente de entrega.</li>
                @endforelse
            </ul>
        </section>
    </div>
</x-layouts.staff>
