@props(['categories'])

@php
    $photos = ['tomatodos' => [385, 643], 'macetas' => [461, 780], 'juguetes' => [562, 380]];
@endphp

<section class="catalog-tunnel" id="catalogo" aria-labelledby="tunnel-title" data-catalog-tunnel>
    <div class="wrap tunnel-inner">
        <header class="tunnel-header">
            <h2 id="tunnel-title">Todo el catálogo</h2>
            <a href="#regalos" class="tunnel-skip">Ir a la guía de regalos</a>
        </header>
        <div class="tunnel-runway" data-tunnel-runway>
            <div class="tunnel-stage" data-tunnel-stage>
                <div class="tunnel-depth" aria-hidden="true">
                    <div class="tunnel-portal"></div>
                    @foreach ($categories as $category)
                        <figure class="tunnel-frame" data-tunnel-frame="{{ $category->name }}">
                            @if (isset($photos[$category->slug]))
                                <img class="tunnel-photo" src="{{ asset('brand/escenas/'.$category->slug.'.webp') }}" alt="" width="768" height="512" loading="lazy" decoding="async">
                                <img class="tunnel-object" src="{{ asset('brand/escenas/'.$category->slug.'-objeto.webp') }}" alt="" width="{{ $photos[$category->slug][0] }}" height="{{ $photos[$category->slug][1] }}" loading="lazy" decoding="async">
                            @else
                                <x-store.product-art :category="$category->slug" />
                            @endif
                            <figcaption>{{ $category->name }}</figcaption>
                        </figure>
                    @endforeach
                </div>
                <div class="tunnel-controls" data-tunnel-controls hidden>
                    <button type="button" data-tunnel-step="-1" aria-label="Categoría anterior"><span aria-hidden="true">‹</span></button>
                    <span class="tunnel-position" data-tunnel-position aria-hidden="true"></span>
                    <button type="button" data-tunnel-step="1" aria-label="Categoría siguiente"><span aria-hidden="true">›</span></button>
                </div>
                <span class="sr-only" role="status" data-tunnel-announcement></span>
            </div>
        </div>
        <ul class="tunnel-categories" aria-label="Categorías de productos">
            @foreach ($categories as $category)
                <li>
                    <a href="{{ route('store.category', $category->slug) }}">
                        <strong>{{ $category->name }}</strong><span>{{ $category->products_count }} productos</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
</section>
