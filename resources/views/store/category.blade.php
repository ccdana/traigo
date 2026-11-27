@use('App\Services\Store\StoreCatalog')

<x-layouts.store :title="$category->name">
    <div class="wrap py-6">
        <x-breadcrumb :trail="['Inicio' => route('store.home')]" :current="$category->name" />

        <div class="mt-3 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-4xl">{{ $category->name }}</h1>
                <p class="mt-1 text-sm text-muted">{{ $products->total() }} {{ $products->total() === 1 ? 'producto' : 'productos' }} · precios al día de hoy</p>
            </div>

            <form method="GET" class="flex items-center gap-2 text-sm">
                @if ($range)<input type="hidden" name="precio" value="{{ $range }}">@endif
                <label for="orden" class="text-muted">Ordenar:</label>
                <select id="orden" name="orden" data-autosubmit class="field w-auto py-1.5">
                    @foreach (StoreCatalog::SORTS as $value => $label)
                        <option value="{{ $value }}" @selected($sort === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <button type="submit" data-autosubmit-button class="chip">Aplicar</button>
            </form>
        </div>

        @php($keep = $sort === 'precio' ? [] : ['orden' => $sort])
        <ul class="mt-5 flex flex-wrap gap-2" aria-label="Filtrar por precio">
            <li><a @class([$range === null ? 'chip-active' : 'chip']) href="{{ route('store.category', ['slug' => $category->slug, ...$keep]) }}">Todos</a></li>
            @foreach (StoreCatalog::PRICE_RANGES as $value => [$label])
                <li><a @class([$range === $value ? 'chip-active' : 'chip']) href="{{ route('store.category', ['slug' => $category->slug, 'precio' => $value, ...$keep]) }}">{{ $label }}</a></li>
            @endforeach
        </ul>

        @if ($products->isEmpty())
            <p class="mt-6 bg-soft p-6 text-sm text-muted">No hay productos en ese rango de precio. <a class="font-medium text-brand underline" href="{{ route('store.category', $category->slug) }}">Ver todos</a></p>
        @else
            <ul class="mt-6 grid grid-cols-2 gap-x-4 gap-y-8 md:grid-cols-3 lg:grid-cols-4">
                @foreach ($products as $product)
                    <li class="flex min-w-0"><x-store.product-card :product="$product" class="w-full" /></li>
                @endforeach
            </ul>
            {{ $products->links('components.pagination') }}
        @endif
    </div>
</x-layouts.store>
