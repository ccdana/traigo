@use('App\Enums\MovementType')
@use('Illuminate\Support\Carbon')
@use('Illuminate\Support\Str')

@php($available = $product->stock - $product->reserved)

<x-layouts.staff :title="$product->name">
    <x-breadcrumb :trail="['Inventario' => route('staff.inventory.index')]" :current="$product->item_code" />

    <div class="mt-3 flex flex-wrap items-center gap-3">
        <h1 class="text-3xl">{{ $product->name }}</h1>
        <x-staff.stock-status :available="$available" />
    </div>
    <p class="mt-1 text-sm text-muted">ITEM {{ $product->item_code }} · {{ $product->category_name ?? 'Sin categoría' }}</p>

    <x-flash class="mt-4" />

    <dl class="mt-5 grid grid-cols-3 gap-3">
        @foreach (['Físico' => $product->stock, 'Reservado' => $product->reserved, 'Disponible' => $available] as $label => $value)
            <div class="flex flex-col-reverse bg-white p-4">
                <dt class="eyebrow mt-1">{{ $label }}</dt>
                <dd class="font-display text-3xl font-bold tabular-nums">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    <div @class(['mt-5 grid items-start gap-5', 'lg:grid-cols-[minmax(0,1fr)_20rem]' => auth()->user()->role->canMoveStock()])>
        <section aria-labelledby="historial" class="min-w-0 bg-white p-4">
            <h2 id="historial" class="text-xl">Historial (kardex)</h2>

            <div class="mt-3 overflow-x-auto">
                <table class="w-full min-w-xl text-left text-sm">
                    <thead class="border-b-2 border-ink">
                        <tr class="eyebrow">
                            <th scope="col" class="py-2 pr-3">Fecha</th>
                            <th scope="col" class="py-2 pr-3">Movimiento</th>
                            <th scope="col" class="py-2 pr-3 text-right">Cantidad</th>
                            <th scope="col" class="py-2 pr-3">Quién</th>
                            <th scope="col" class="py-2">Detalle</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($movements as $movement)
                            <tr class="border-b border-line align-top">
                                <td class="py-2 pr-3 font-mono text-xs whitespace-nowrap">{{ Carbon::parse($movement->created_at)->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</td>
                                <td class="py-2 pr-3">{{ MovementType::from($movement->type)->label() }}</td>
                                <td @class(['py-2 pr-3 text-right font-semibold tabular-nums', 'text-ok' => $movement->quantity > 0, 'text-danger' => $movement->quantity < 0])>{{ $movement->quantity > 0 ? '+' : '' }}{{ $movement->quantity }}</td>
                                <td class="py-2 pr-3">{{ $movement->user_name ?? 'Sistema' }}</td>
                                <td class="py-2 text-muted">
                                    @if ($movement->order_number)Pedido {{ $movement->order_number }}@endif
                                    {{ $movement->note }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-6 text-center text-muted">Todavía no hay movimientos. El primero suele ser el inventario inicial.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $movements->links('components.pagination') }}
        </section>

        @if (auth()->user()->role->canMoveStock())
            <section aria-labelledby="registrar" class="bg-white p-4">
                <h2 id="registrar" class="text-xl">Registrar movimiento</h2>
                <p class="mt-1 text-xs text-muted">El historial no se edita: un error se corrige con otro movimiento.</p>

                <form method="POST" action="{{ route('staff.inventory.movements.store', $product->id_product) }}" class="mt-4 space-y-4">
                    @csrf
                    <input type="hidden" name="client_uuid" value="{{ old('client_uuid', (string) Str::uuid()) }}">

                    <div>
                        <label for="type" class="eyebrow">Tipo</label>
                        <select id="type" name="type" required class="field mt-1">
                            @foreach (MovementType::manual() as $type)
                                <option value="{{ $type->value }}" @selected(old('type', 'purchase') === $type->value)>{{ $type->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="quantity" class="eyebrow">Cantidad de unidades</label>
                        <input id="quantity" type="number" name="quantity" value="{{ old('quantity') }}" required min="1" max="100000" inputmode="numeric" class="field mt-1">
                    </div>
                    <div>
                        <label for="note" class="eyebrow">Motivo (obligatorio en ajustes)</label>
                        <textarea id="note" name="note" rows="3" maxlength="500" class="field mt-1">{{ old('note') }}</textarea>
                    </div>
                    <button type="submit" class="btn-primary w-full">Registrar</button>
                </form>
            </section>
        @endif
    </div>
</x-layouts.staff>
