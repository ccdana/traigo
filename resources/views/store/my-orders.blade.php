@use('App\Http\Resources\SaleOrder')
@use('App\Support\CustomerOrderStatus')
@use('App\Support\Dates')
@use('App\Support\Money')

<x-layouts.store title="Mis pedidos">
    <div class="wrap py-6">
        <x-breadcrumb :trail="['Inicio' => route('store.home')]" current="Mis pedidos" />
        <h1 class="mt-3 text-4xl">Mis pedidos</h1>
        <p class="mt-1 text-sm text-muted">Los pedidos de tu cuenta, del más nuevo al más antiguo.</p>

        @if ($orders->isEmpty())
            <div class="mt-6 bg-soft p-8">
                <p class="text-muted">Todavía no tienes pedidos.</p>
                <a href="{{ route('store.home') }}#regalos" class="btn-primary mt-4">Ver regalos</a>
            </div>
        @else
            <ul class="mt-6 border-t border-line">
                @foreach ($orders as $order)
                    <li class="relative flex flex-wrap items-center justify-between gap-3 border-b border-line py-4 hover:bg-soft">
                        <div class="min-w-0">
                            <p class="eyebrow">{{ $order->order_number }} · {{ Dates::short($order->created_at) }}</p>
                            <a href="{{ route('store.order.show', $order->public_token) }}" class="font-medium after:absolute after:inset-0">{{ SaleOrder::itemsLabel($order) }}</a>
                        </div>
                        <div class="text-right">
                            <p class="font-display text-lg font-bold">{{ Money::display(Money::toCents($order->total_bs)) }}</p>
                            <p class="text-xs font-medium text-brand">{{ CustomerOrderStatus::label($order->status) }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
            {{ $orders->links('components.pagination') }}
        @endif
    </div>
</x-layouts.store>
