@props(['categories'])

<section class="cargo-carousel" aria-labelledby="cargo-title" data-category-carousel>
    <div class="cargo-heading">
        <h2 id="cargo-title">Explora el catálogo</h2>
        <img class="cargo-mark" src="{{ asset('brand/traigo-mark.png') }}" alt="" width="28" height="28">
    </div>

    <div class="cargo-stage" data-cargo-stage>
        @foreach ($categories as $category)
            <div class="cargo-slide" data-cargo-slide data-category-name="{{ $category->name }}">
                <div class="cargo-parcel">
                    <a class="cargo-label" href="{{ route('store.category', $category->slug) }}">
                        <x-store.product-art :category="$category->slug" />
                        <span class="cargo-description">
                            <span>
                                <strong>{{ $category->name }}</strong>
                                <span class="cargo-count">{{ $category->products_count }} {{ $category->products_count === 1 ? 'producto' : 'productos' }}</span>
                            </span>
                            <span class="cargo-link-arrow" aria-hidden="true">↗</span>
                        </span>
                    </a>
                </div>
            </div>
        @endforeach
    </div>

    <div class="cargo-controls" data-cargo-controls hidden>
        <div class="cargo-position" aria-hidden="true"><span data-cargo-current></span><span>/</span><span>{{ sprintf('%02d', $categories->count()) }}</span></div>
        <span class="cargo-hint">Arrastra para explorar</span>
        <p class="sr-only" role="status" data-cargo-status></p>
        <div class="cargo-arrows">
            <button type="button" data-cargo-step="-1" aria-label="Categoría anterior">←</button>
            <button type="button" data-cargo-step="1" aria-label="Categoría siguiente">→</button>
        </div>
    </div>
</section>
