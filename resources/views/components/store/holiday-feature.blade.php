@props(['offer' => null])

@use('App\Support\Money')

<section class="wrap py-3" aria-labelledby="holiday-heading">
    <div class="holiday-feature" data-holiday-reveal>
        <div class="holiday-feature-copy">
            <h2 id="holiday-heading">Abre la Navidad</h2>
            <p>Descubre las promociones de diciembre en nuestro calendario de regalos.</p>
            <a href="#calendario" class="holiday-action">Ver calendario de ofertas</a>
        </div>

        @if ($offer)
            <a class="holiday-deal" href="{{ route('store.product', $offer['card']->slug) }}">
                <span class="holiday-day">{{ $offer['day'] }}<small>Dic</small></span>
                <span class="holiday-deal-copy">
                    <span class="text-xs font-semibold">Oferta del calendario</span>
                    <strong>{{ $offer['title'] }}</strong>
                    <span>{{ Money::display($offer['card']->priceCents) }}</span>
                </span>
            </a>
        @else
            <div class="holiday-parcel" aria-hidden="true">
                <div class="holiday-package">
                    <img src="{{ asset('brand/escenas/regalo.webp') }}" alt="" width="768" height="512" loading="lazy" decoding="async">
                </div>
                <div class="holiday-label"><span>01–24</span><small>Diciembre</small></div>
            </div>
        @endif
    </div>
</section>
