@use('App\Support\Money')

<x-layouts.staff title="Productos">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-3xl">Productos</h1>
        <a href="{{ route('staff.products.create') }}" class="btn-primary">Nuevo producto</a>
    </div>
    <x-flash class="mt-4" />

    <form method="GET" class="mt-4 grid gap-3 bg-white p-4 sm:grid-cols-[minmax(0,1fr)_auto_auto] sm:items-end">
        <div>
            <label for="buscar" class="eyebrow">Buscar por nombre o ITEM</label>
            <input id="buscar" type="search" name="buscar" value="{{ $search }}" maxlength="80" class="field mt-1">
        </div>
        <div>
            <label for="venta" class="eyebrow">A la venta</label>
            <select id="venta" name="venta" class="field mt-1">
                <option value="">Todos</option>
                <option value="si" @selected($sale === 'si')>Sí</option>
                <option value="no" @selected($sale === 'no')>No</option>
            </select>
        </div>
        <button type="submit" class="btn-dark">Filtrar</button>
    </form>

    <div class="mt-4 overflow-x-auto bg-white">
        <table class="w-full min-w-2xl text-left text-sm">
            <thead class="border-b-2 border-ink">
                <tr class="eyebrow">
                    <th scope="col" class="px-3 py-3">ITEM</th>
                    <th scope="col" class="px-3 py-3">Producto</th>
                    <th scope="col" class="px-3 py-3 text-right">Unidad</th>
                    <th scope="col" class="px-3 py-3 text-right">Por mayor</th>
                    <th scope="col" class="px-3 py-3 text-right">Fotos</th>
                    <th scope="col" class="px-3 py-3">A la venta</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($products as $product)
                    <tr class="border-b border-line hover:bg-soft">
                        <td class="px-3 py-2.5 font-mono text-xs whitespace-nowrap">{{ $product->item_code }}</td>
                        <td class="px-3 py-2.5">
                            <a href="{{ route('staff.products.edit', $product->id_product) }}" class="font-medium text-brand hover:underline">{{ $product->name }}</a>
                            <span class="block text-xs text-muted">{{ $product->category_name ?? 'Sin categoría' }}</span>
                        </td>
                        <td class="px-3 py-2.5 text-right whitespace-nowrap tabular-nums">{{ Money::display(Money::toCents($product->price_unit_bs)) }}</td>
                        <td class="px-3 py-2.5 text-right whitespace-nowrap tabular-nums">{{ $product->price_wholesale_bs === null ? '—' : Money::display(Money::toCents($product->price_wholesale_bs))." · {$product->wholesale_min_qty} u." }}</td>
                        <td class="px-3 py-2.5 text-right tabular-nums">{{ $product->images_count }}</td>
                        <td class="px-3 py-2.5"><span @class(['badge', 'bg-ok-soft text-ok' => $product->is_active, 'bg-warn-soft text-warn' => ! $product->is_active])>{{ $product->is_active ? 'Sí' : 'No' }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-3 py-8 text-center text-muted">Ningún producto coincide con el filtro.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $products->links('components.pagination') }}
</x-layouts.staff>
