@use('App\Support\Money')

<x-layouts.store title="Tu carrito">
    <div class="wrap py-6">
        <x-breadcrumb :trail="['Inicio' => route('store.home')]" current="Carrito" />
        <h1 class="mt-3 text-4xl">Tu carrito</h1>

        <x-flash class="mt-4" />
        @if ($removed)
            <p role="status" class="mt-4 border-l-4 border-warn bg-warn-soft px-4 py-3 text-sm text-warn">Quitamos de tu carrito lo que ya no está a la venta.</p>
        @endif

        @if ($lines->isEmpty())
            <div class="mt-6 bg-soft p-8">
                <p class="text-muted">Tu carrito está vacío.</p>
                <a href="{{ route('store.home') }}#regalos" class="btn-primary mt-4">Ver regalos</a>
            </div>
        @else
            <p class="mt-1 text-sm text-muted">{{ $lines->count() }} {{ $lines->count() === 1 ? 'producto' : 'productos' }} · {{ $units }} {{ $units === 1 ? 'unidad' : 'unidades' }}</p>

            <div class="mt-5 grid items-start gap-8 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <div>
                    <ul class="border-t border-line">
                        @foreach ($lines as $line)
                            @php($product = $line['product'])
                            <li class="grid grid-cols-[4.5rem_minmax(0,1fr)] items-center gap-x-4 gap-y-3 border-b border-line py-4 sm:grid-cols-[4.5rem_minmax(0,1fr)_auto_6rem]">
                                <x-store.product-art :category="$product->categorySlug" :name="$product->name" :image="$product->imageUrl" class="aspect-square" />
                                <div class="min-w-0">
                                    <p class="eyebrow">{{ $product->itemCode }}</p>
                                    <a href="{{ route('store.product', $product->slug) }}" class="font-medium hover:text-brand">{{ $product->name }}</a>
                                    <p class="text-xs text-muted">{{ Money::display($line['unit_cents']) }} c/u · {{ $line['price_type'] === 'wholesale' ? 'por mayor' : 'por unidad' }}</p>
                                    @if ($line['missing_for_wholesale'])
                                        <p class="text-xs font-medium text-brand">Con {{ $product->wholesaleMinQty }} u. pasa a {{ Money::display($product->wholesaleCents) }} c/u. Te faltan {{ $line['missing_for_wholesale'] }}.</p>
                                    @endif
                                    @if ($line['exceeds_stock'])
                                        <p class="text-xs font-semibold text-danger">{{ $product->available === 0 ? 'Se agotó.' : "Solo quedan {$product->available}." }} Ajusta la cantidad.</p>
                                    @endif
                                    <form method="POST" action="{{ route('store.bag.destroy', $product->id) }}">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="mt-1 cursor-pointer text-xs text-muted underline hover:text-danger">Quitar</button>
                                    </form>
                                </div>
                                <div class="col-start-2 flex items-center gap-1 sm:col-start-auto">
                                    <form method="POST" action="{{ route('store.bag.update', $product->id) }}">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="quantity" value="{{ $line['quantity'] - 1 }}">
                                        <button type="submit" class="btn-soft border-b-2 border-ink px-4 py-2" aria-label="Una unidad menos de {{ $product->name }}">−</button>
                                    </form>
                                    <form method="POST" action="{{ route('store.bag.update', $product->id) }}">
                                        @csrf @method('PATCH')
                                        <label for="cantidad-{{ $product->id }}" class="sr-only">Cantidad de {{ $product->name }}</label>
                                        <input id="cantidad-{{ $product->id }}" type="number" name="quantity" value="{{ $line['quantity'] }}" min="0" max="999" inputmode="numeric" class="w-16 border-0 bg-transparent py-2 text-center font-display text-lg font-bold">
                                    </form>
                                    <form method="POST" action="{{ route('store.bag.update', $product->id) }}">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="quantity" value="{{ $line['quantity'] + 1 }}">
                                        <button type="submit" class="btn-soft border-b-2 border-ink px-4 py-2" aria-label="Una unidad más de {{ $product->name }}">+</button>
                                    </form>
                                </div>
                                <p class="col-start-2 font-display text-lg font-bold sm:col-start-auto sm:text-right">{{ Money::display($line['total_cents']) }}</p>
                            </li>
                        @endforeach
                    </ul>
                    <a href="{{ route('store.home') }}#regalos" class="mt-4 inline-block text-sm font-medium text-brand underline">← Seguir comprando</a>
                </div>

                <aside class="cut bg-soft p-5" aria-labelledby="resumen">
                    <h2 id="resumen" class="eyebrow text-brand">Resumen</h2>
                    <dl class="mt-3 space-y-2 text-sm">
                        <div class="flex justify-between gap-3"><dt>Subtotal</dt><dd class="font-mono font-semibold">{{ Money::display($subtotal_cents) }}</dd></div>
                        <div class="flex justify-between gap-3 text-muted"><dt>Envío</dt><dd class="font-mono">al elegir entrega</dd></div>
                        <div class="flex items-baseline justify-between gap-3 border-t-2 border-ink pt-3">
                            <dt class="font-display font-bold">Total</dt>
                            <dd class="font-display text-2xl font-bold">{{ Money::display($subtotal_cents) }}</dd>
                        </div>
                    </dl>

                    @if ($canCheckout && ! $has_shortage)
                        <a href="{{ route('store.checkout.create') }}" class="btn-primary mt-4 w-full">Continuar al pago</a>
                        @guest('customer')
                            <p class="mt-3 text-xs font-medium">Para comprar te pediremos ingresar o crear tu cuenta. Tu carrito se conserva.</p>
                        @endguest
                        <p class="mt-3 text-xs text-muted">Al confirmar, reservamos tu pedido por {{ $hours }} horas mientras pagas.</p>
                    @else
                        <button type="button" class="btn-primary mt-4 w-full" disabled>Continuar al pago</button>
                        <p class="mt-3 text-xs text-muted">{{ $has_shortage ? 'Ajusta las cantidades marcadas para continuar.' : 'El pago en línea todavía no está disponible.' }}</p>
                    @endif
                    @if ($orderUrl && ! $canCheckout && ! $has_shortage)
                        <a href="{{ $orderUrl }}" class="btn-dark mt-2 w-full" target="_blank" rel="noopener">Pedir por WhatsApp</a>
                    @endif
                </aside>
            </div>
        @endif
    </div>
</x-layouts.store>
