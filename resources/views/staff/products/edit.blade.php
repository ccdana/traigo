@use('App\Services\Catalog\ProductImages')

@php
    $role = auth()->user()->role;
    $labels = ['price_unit' => 'Precio por unidad (Bs)', 'price_wholesale' => 'Precio por mayor (Bs)', 'wholesale_min_qty' => 'Mínimo por mayor', 'commission_pct' => 'Comisión %', 'cost' => 'Costo (Bs)'];
@endphp

<x-layouts.staff :title="$product->name">
    <x-breadcrumb :trail="['Productos' => route('staff.products.index')]" :current="$product->item_code" />

    <div class="mt-3 flex flex-wrap items-center gap-3">
        <h1 class="text-3xl">{{ $product->name }}</h1>
        <span @class(['badge', 'bg-ok-soft text-ok' => $product->is_active, 'bg-warn-soft text-warn' => ! $product->is_active])>{{ $product->is_active ? 'A la venta' : 'Fuera de la venta' }}</span>
    </div>
    <p class="mt-1 flex flex-wrap gap-3 text-sm">
        <a href="{{ route('staff.inventory.show', $product->id_product) }}" class="text-brand underline">Ver stock e historial</a>
        @if ($product->is_active)<a href="{{ route('store.product', $product->slug) }}" class="text-brand underline">Ver en la tienda</a>@endif
    </p>
    <x-flash class="mt-4" />

    @if ($product->is_active || $role->canAuthorize())
        <form method="POST" action="{{ route('staff.products.sale', $product->id_product) }}" class="mt-4">
            @csrf @method('PATCH')
            <input type="hidden" name="active" value="{{ $product->is_active ? 0 : 1 }}">
            <button type="submit" class="{{ $product->is_active ? 'btn-soft bg-white' : 'btn-primary' }}">{{ $product->is_active ? 'Quitar de la venta' : 'Poner a la venta' }}</button>
        </form>
    @else
        <p class="mt-4 bg-white p-4 text-sm text-muted">Dirección es quien lo pone a la venta.</p>
    @endif

    <div class="mt-5 grid items-start gap-5 lg:grid-cols-2">
        <section class="bg-white p-5" aria-labelledby="datos">
            <h2 id="datos" class="text-xl">Datos</h2>
            <form method="POST" action="{{ route('staff.products.update', $product->id_product) }}" class="mt-3 space-y-4">
                @csrf @method('PATCH')
                <x-staff.product-details :categories="$categories" :product="$product" />
                <button type="submit" class="btn-dark">Guardar datos</button>
            </form>
        </section>

        <div class="space-y-5">
            <section class="bg-white p-5" aria-labelledby="fotos">
                <h2 id="fotos" class="text-xl">Fotos</h2>
                @if ($images->isEmpty())
                    <p class="mt-2 text-sm text-muted">Sin fotos: la tienda muestra una ilustración.</p>
                @else
                    <ul class="mt-3 grid grid-cols-3 gap-3">
                        @foreach ($images as $image)
                            <li>
                                <img src="{{ $image->url }}" alt="Foto {{ $loop->iteration }} de {{ $product->name }}" class="aspect-square w-full bg-soft object-cover">
                                <div class="mt-1 flex flex-wrap gap-2 text-xs">
                                    @if ($loop->first)
                                        <span class="font-semibold text-brand">Principal</span>
                                    @else
                                        <form method="POST" action="{{ route('staff.products.images.main', [$product->id_product, $image->id_product_image]) }}">
                                            @csrf @method('PATCH')
                                            <button type="submit" class="cursor-pointer text-brand underline">Principal</button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('staff.products.images.destroy', [$product->id_product, $image->id_product_image]) }}">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="cursor-pointer text-danger underline">Borrar</button>
                                    </form>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($images->count() < ProductImages::MAX_PER_PRODUCT)
                    <form method="POST" action="{{ route('staff.products.images.store', $product->id_product) }}" enctype="multipart/form-data" data-once class="mt-4 space-y-3">
                        @csrf
                        <label for="photo" class="eyebrow">Agregar foto (JPG, PNG o WEBP, hasta 5 MB)</label>
                        <input id="photo" type="file" name="photo" accept="image/jpeg,image/png,image/webp" required class="field">
                        <button type="submit" class="btn-dark">Subir foto</button>
                    </form>
                @endif
            </section>

            <section class="bg-white p-5" aria-labelledby="precios">
                <h2 id="precios" class="text-xl">Precios</h2>
                <dl class="mt-3 text-sm">
                    @foreach ($labels as $key => $label)
                        <div class="flex justify-between gap-3 border-b border-line py-1.5">
                            <dt class="text-muted">{{ $label }}</dt>
                            <dd class="font-mono font-semibold">{{ $sheet['current'][$key] ?? 'Por definir' }}</dd>
                        </div>
                    @endforeach
                </dl>

                @if ($pending = $sheet['pending_change'])
                    <div class="mt-3 bg-warn-soft p-3 text-sm">
                        <p class="font-semibold">Esperando a Dirección (pidió {{ $pending['requested_by'] }}):</p>
                        <ul class="mt-1">
                            @foreach ($pending['proposed'] as $key => $value)
                                <li>{{ $labels[$key] }}: {{ $value }}</li>
                            @endforeach
                        </ul>
                    </div>
                @elseif ($role->canProposePrices())
                    <form method="POST" action="{{ route('staff.products.prices', $product->id_product) }}" data-once class="mt-4 space-y-3">
                        @csrf
                        <p class="text-xs text-muted">Escribe solo lo que cambia. Vale cuando Dirección lo aprueba.</p>
                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach ($labels as $key => $label)
                                <x-field :name="$key" :label="$label" inputmode="decimal" />
                            @endforeach
                        </div>
                        <x-field name="reason" label="Motivo" maxlength="500" />
                        <button type="submit" class="btn-dark">Proponer cambio</button>
                    </form>
                @else
                    <p class="mt-3 text-xs text-muted">Los cambios de precio los propone Jime.</p>
                @endif
            </section>
        </div>
    </div>
</x-layouts.staff>
