@use('Illuminate\Support\Carbon')
@use('App\Support\Dates')

<x-layouts.staff title="Autorizaciones">
    <h1 class="text-3xl">Autorizaciones de la tienda</h1>
    <p class="mt-1 text-sm text-muted">Precios y ofertas que esperan a Dirección. El bono y las comisiones se autorizan en la app.</p>
    <x-flash class="mt-4" />

    @if ($unpublished > 0)
        <p class="mt-4 bg-white p-4 text-sm">
            Hay {{ $unpublished }} {{ $unpublished === 1 ? 'producto fuera' : 'productos fuera' }} de la venta.
            <a href="{{ route('staff.products.index', ['venta' => 'no']) }}" class="font-medium text-brand underline">Revisarlos</a>
        </p>
    @endif

    <ul class="mt-4 space-y-3">
        @forelse ($items as $item)
            <li class="relative bg-white p-4 hover:bg-soft">
                <a href="{{ route('staff.authorizations.show', [$item['type'], $item['id']]) }}" class="font-display text-lg font-bold after:absolute after:inset-0">{{ $item['title'] }}</a>
                <p class="text-sm text-muted">Pidió {{ $item['requested_by'] }} · {{ Dates::short(Carbon::parse($item['requested_at'])) }}</p>
            </li>
        @empty
            <li class="bg-white p-6 text-sm text-muted">No hay nada esperando autorización.</li>
        @endforelse
    </ul>
</x-layouts.staff>
