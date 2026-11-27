@use('App\Support\Dates')
@use('App\Support\Money')
@use('App\Services\Control\PriceChanges')

<x-layouts.staff title="Ofertas">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-3xl">Ofertas de la tienda</h1>
        @if (auth()->user()->role->canCreatePromotions())
            <a href="{{ route('staff.promotions.create') }}" class="btn-primary">Nueva oferta</a>
        @endif
    </div>
    <p class="mt-1 text-sm text-muted">Solo se publican autorizadas por Dirección y nunca bajan del precio por mayor.</p>
    <x-flash class="mt-4" />

    <ul class="mt-4 space-y-3">
        @forelse ($promotions as $promotion)
            @php
                [$state, $tone] = match (true) {
                    ! $promotion->is_active => ['Retirada', 'bg-soft text-muted'],
                    $promotion->status === 'pending' => ['Esperando a Dirección', 'bg-warn-soft text-warn'],
                    $promotion->status === 'rejected' => ['No aprobada', 'bg-danger-soft text-danger'],
                    default => ['Autorizada', 'bg-ok-soft text-ok'],
                };
                $discount = match ($promotion->discount_type) {
                    'percentage' => PriceChanges::percent($promotion->discount_value).' de descuento',
                    'fixed_amount' => Money::display((int) round((float) $promotion->discount_value * 100)).' menos por unidad',
                    default => Money::display((int) round((float) $promotion->discount_value * 100)).' por unidad',
                };
            @endphp
            <li class="bg-white p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-lg">{{ $promotion->name }}</h2>
                    <span class="badge {{ $tone }}">{{ $state }}</span>
                </div>
                <p class="mt-1 text-sm">{{ $discount }} · desde el {{ Dates::short($promotion->starts_at) }}{{ $promotion->ends_at ? ' hasta el '.Dates::short($promotion->ends_at) : '' }}{{ $promotion->calendar_day ? " · puerta {$promotion->calendar_day}" : '' }}</p>
                <p class="mt-1 text-sm text-muted">{{ $promotion->products ?? 'Sin productos' }}</p>
                @if ($promotion->is_active)
                    <form method="POST" action="{{ route('staff.promotions.withdraw', $promotion->id_promotion) }}" class="mt-2">
                        @csrf @method('PATCH')
                        <button type="submit" class="cursor-pointer text-sm text-danger underline">Retirar</button>
                    </form>
                @endif
            </li>
        @empty
            <li class="bg-white p-6 text-sm text-muted">Todavía no hay ofertas.</li>
        @endforelse
    </ul>

    {{ $promotions->links('components.pagination') }}
</x-layouts.staff>
