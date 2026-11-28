@use('App\Support\Money')

@php
    $trail = ['Inicio' => route('store.home')];
    if ($product->categorySlug) {
        $trail[$product->categoryName] = route('store.category', $product->categorySlug);
    }
@endphp

<x-layouts.store :title="$product->name" :description="$description ?: $product->name.' · '.Money::display($product->priceCents).' en Traigo, Cochabamba.'">
    <div class="wrap py-6">
        <x-breadcrumb :trail="$trail" :current="$product->name" />
        <x-flash class="mt-4" />

        <div class="mt-4 grid gap-8 md:grid-cols-2">
            <div data-gallery class="min-w-0 space-y-3">
                @if ($images)
                    <div class="aspect-4/3 overflow-hidden bg-soft">
                        <img data-gallery-main src="{{ $images[0] }}" alt="{{ $product->name }}" class="size-full object-contain">
                    </div>
                    @if (count($images) > 1)
                        <ul class="grid grid-cols-4 gap-2">
                            @foreach ($images as $index => $image)
                                <li>
                                    <button type="button" data-gallery-thumb="{{ $image }}" class="block aspect-square w-full cursor-pointer overflow-hidden border-2 border-line hover:border-brand">
                                        <img src="{{ $image }}" alt="Foto {{ $index + 1 }}" class="size-full object-cover" loading="lazy">
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @else
                    <x-store.product-art :category="$product->categorySlug" :name="$product->name" />
                @endif
            </div>

            <div class="min-w-0 space-y-4">
                <p class="eyebrow">{{ $product->itemCode }} · {{ $product->categoryName }}</p>
                <h1 class="text-3xl">{{ $product->name }}</h1>
                <p class="flex flex-wrap items-baseline gap-3">
                    <span class="font-display text-4xl font-bold">{{ Money::display($product->priceCents) }}</span>
                    @if ($product->listCents)
                        <s class="text-muted">{{ Money::display($product->listCents) }}</s>
                        <span class="badge bg-brand text-white">Oferta</span>
                    @endif
                </p>
                <x-store.availability :available="$product->isAvailable()" />

                @if ($description)
                    <p class="text-sm leading-relaxed whitespace-pre-line text-muted">{{ $description }}</p>
                @endif

                @if ($product->wholesaleCents !== null)
                    <div class="cut bg-soft p-4">
                        <h2 class="eyebrow mb-2 text-brand">Compra por mayor</h2>
                        <dl class="space-y-1.5 text-sm">
                            <div class="flex justify-between gap-3">
                                <dt>1 a {{ $product->wholesaleMinQty - 1 }} unidades</dt>
                                <dd class="font-mono font-semibold">{{ Money::display($product->priceCents) }} c/u</dd>
                            </div>
                            <div class="flex justify-between gap-3 border-t border-line pt-1.5">
                                <dt>Desde {{ $product->wholesaleMinQty }} del mismo código</dt>
                                <dd class="font-mono font-semibold">{{ Money::display($product->wholesaleCents) }} c/u</dd>
                            </div>
                        </dl>
                    </div>
                @endif

                <form method="POST" action="{{ route('store.bag.store') }}" class="flex flex-wrap items-center gap-3">
                    @csrf
                    <input type="hidden" name="product_id" value="{{ $product->id }}">
                    <div data-stepper class="flex items-center gap-1">
                        <button type="button" data-step="-1" hidden class="btn-soft border-b-2 border-ink px-4 py-2.5" aria-label="Quitar una unidad">−</button>
                        <label for="cantidad" class="sr-only">Cantidad</label>
                        <input id="cantidad" type="number" name="quantity" value="1" min="1" max="{{ max(1, $product->available) }}" inputmode="numeric" class="field w-20 text-center font-display font-semibold">
                        <button type="button" data-step="1" hidden class="btn-soft border-b-2 border-ink px-4 py-2.5" aria-label="Agregar una unidad">+</button>
                    </div>
                    <button type="submit" class="btn-primary" @disabled(! $product->isAvailable())>
                        {{ $product->isAvailable() ? 'Agregar al carrito' : 'Agotado' }}
                    </button>
                </form>

                @if ($askUrl)
                    <a href="{{ $askUrl }}" class="inline-block text-sm font-medium text-brand underline" target="_blank" rel="noopener">Consultar por WhatsApp</a>
                @endif

                <dl class="text-sm">
                    @foreach (['Entrega' => 'Delivery o recojo', 'Pago' => 'QR bancario', 'Código' => $product->itemCode, 'Categoría' => $product->categoryName] as $label => $value)
                        <div class="flex justify-between gap-3 border-b border-line py-2">
                            <dt class="text-muted">{{ $label }}</dt>
                            <dd class="text-right font-medium">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </div>

        @if ($related->isNotEmpty())
            <section class="mt-12" aria-labelledby="relacionados">
                <h2 id="relacionados" class="text-2xl">También para regalar</h2>
                <ul class="mt-5 grid grid-cols-2 gap-x-4 gap-y-8 md:grid-cols-4">
                    @foreach ($related as $item)
                        <li class="flex min-w-0"><x-store.product-card :product="$item" class="w-full" /></li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</x-layouts.store>
