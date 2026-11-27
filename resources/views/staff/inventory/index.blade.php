@use('App\Services\Warehouse\StockOverview')
@use('App\Support\Money')

<x-layouts.staff title="Inventario">
    <h1 class="text-3xl">Inventario</h1>
    <p class="mt-1 text-sm text-muted">El mismo stock que usan la app y la tienda. Disponible = físico − reservado.</p>

    <x-flash class="mt-4" />

    <dl class="mt-5 grid grid-cols-2 gap-3 md:grid-cols-5">
        @foreach ([
            ['Unidades en bodega', $summary->stock, null],
            ['Reservadas', $summary->reserved, null],
            ['Disponibles', $summary->available, null],
            ['Productos con stock bajo', $summary->low_stock, 'low'],
            ['Productos agotados', $summary->out_of_stock, 'out'],
        ] as [$label, $value, $filter])
            <div class="relative flex flex-col-reverse bg-white p-4">
                <dt class="eyebrow mt-1">{{ $label }}</dt>
                <dd class="font-display text-3xl font-bold tabular-nums">
                    @if ($filter)
                        <a href="{{ route('staff.inventory.index', ['estado' => $filter]) }}" @class(['after:absolute after:inset-0', 'text-danger' => $filter === 'out' && $value > 0, 'text-warn' => $filter === 'low' && $value > 0])>{{ $value }}</a>
                    @else
                        {{ $value }}
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>

    <form method="GET" class="mt-5 grid gap-3 bg-white p-4 sm:grid-cols-[minmax(0,1fr)_auto_auto_auto] sm:items-end">
        <div>
            <label for="buscar" class="eyebrow">Buscar por nombre o ITEM</label>
            <input id="buscar" type="search" name="buscar" value="{{ $search }}" maxlength="80" class="field mt-1">
        </div>
        <div>
            <label for="categoria" class="eyebrow">Categoría</label>
            <select id="categoria" name="categoria" class="field mt-1">
                <option value="">Todas</option>
                @foreach ($categories as $option)
                    <option value="{{ $option->id_category }}" @selected($category === $option->id_category)>{{ $option->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="estado" class="eyebrow">Estado</label>
            <select id="estado" name="estado" class="field mt-1">
                <option value="">Todos</option>
                @foreach (StockOverview::STATUSES as $value => $label)
                    <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex gap-2">
            <button type="submit" class="btn-dark">Filtrar</button>
            @if ($search || $category || $status)
                <a href="{{ route('staff.inventory.index') }}" class="btn-soft">Limpiar</a>
            @endif
        </div>
    </form>

    <div class="mt-4 overflow-x-auto bg-white">
        <table class="w-full min-w-2xl text-left text-sm">
            <thead class="border-b-2 border-ink">
                <tr class="eyebrow">
                    <th scope="col" class="px-3 py-3">ITEM</th>
                    <th scope="col" class="px-3 py-3">Producto</th>
                    <th scope="col" class="px-3 py-3 text-right">Precio</th>
                    <th scope="col" class="px-3 py-3 text-right">Físico</th>
                    <th scope="col" class="px-3 py-3 text-right">Reservado</th>
                    <th scope="col" class="px-3 py-3 text-right">Disponible</th>
                    <th scope="col" class="px-3 py-3">Estado</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($products as $product)
                    @php($available = $product->stock - $product->reserved)
                    <tr class="border-b border-line hover:bg-soft">
                        <td class="px-3 py-2.5 font-mono text-xs whitespace-nowrap">{{ $product->item_code }}</td>
                        <td class="px-3 py-2.5">
                            <a href="{{ route('staff.inventory.show', $product->id_product) }}" class="font-medium text-brand hover:underline">{{ $product->name }}</a>
                            <span class="block text-xs text-muted">{{ $product->category_name ?? 'Sin categoría' }}@unless ($product->is_active) · No está a la venta @endunless</span>
                        </td>
                        <td class="px-3 py-2.5 text-right whitespace-nowrap tabular-nums">{{ Money::display(Money::toCents($product->price_unit_bs)) }}</td>
                        <td class="px-3 py-2.5 text-right tabular-nums">{{ $product->stock }}</td>
                        <td class="px-3 py-2.5 text-right tabular-nums">{{ $product->reserved }}</td>
                        <td class="px-3 py-2.5 text-right font-semibold tabular-nums">{{ $available }}</td>
                        <td class="px-3 py-2.5 whitespace-nowrap"><x-staff.stock-status :available="$available" /></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-3 py-8 text-center text-muted">Ningún producto coincide con el filtro.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $products->links('components.pagination') }}
</x-layouts.staff>
