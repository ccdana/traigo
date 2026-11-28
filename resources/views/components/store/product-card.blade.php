@props(['product'])

@use('App\Support\Money')

<article {{ $attributes->merge(['class' => 'product-card group relative flex min-w-0 flex-col gap-2']) }}>
    <x-store.product-art :category="$product->categorySlug" :name="$product->name" :image="$product->imageUrl" :alt="$product->name" />
    <p class="eyebrow">{{ $product->itemCode }}</p>
    <h3 class="font-sans text-sm font-medium">
        {{-- El enlace cubre toda la tarjeta. --}}
        <a href="{{ route('store.product', $product->slug) }}" class="after:absolute after:inset-0 group-hover:text-brand">{{ $product->name }}</a>
    </h3>
    <p class="mt-auto flex flex-wrap items-baseline gap-x-2">
        <span class="font-display text-xl font-bold">{{ Money::display($product->priceCents) }}</span>
        @if ($product->listCents)
            <s class="text-xs text-muted">{{ Money::display($product->listCents) }}</s>
            <span class="badge bg-brand text-white">Oferta</span>
        @endif
    </p>
    @if ($product->wholesaleCents !== null)
        <p class="text-xs text-muted">Por mayor {{ Money::display($product->wholesaleCents) }} desde {{ $product->wholesaleMinQty }} u.</p>
    @endif
    <x-store.availability :available="$product->isAvailable()" />
    <span class="product-discover" aria-hidden="true">Ver producto <span>↗</span></span>
</article>
