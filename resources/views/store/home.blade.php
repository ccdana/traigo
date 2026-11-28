@use('App\Services\Store\StoreCatalog')
@use('App\Support\Money')

<x-layouts.store>
    {{-- Portada --}}
    <section class="import-hero" data-import-hero aria-labelledby="hero-title">
        <div class="wrap import-hero-layout">
            <div class="import-hero-copy">
                <h1 id="hero-title">Del mundo a tus manos.</h1>
                <p>Tomatodos, macetas y juguetes importados. Compra al detalle o por mayor en Cochabamba.</p>
                <div class="import-hero-actions">
                    <a href="#regalos" class="btn import-primary">Ver regalos</a>
                    <a href="{{ $categories->isNotEmpty() ? '#catalogo' : '#por-mayor' }}" class="import-text-link">{{ $categories->isNotEmpty() ? 'Explorar categorías' : 'Compra por mayor' }} <span aria-hidden="true">↓</span></a>
                </div>
            </div>
            <div class="import-globe" aria-hidden="true">
                <canvas width="720" height="600" data-import-globe></canvas>
                <div class="import-location"><span></span>Cochabamba, Bolivia</div>
            </div>
        </div>
    </section>

    <x-store.holiday-feature :offer="collect($doors)->first(fn ($door) => $door['card']?->isAvailable())" />

    @if ($categories->isNotEmpty())
        <x-store.catalog-tunnel :categories="$categories" />
    @endif

    {{-- Guía de regalos --}}
    <section id="regalos" class="wrap scroll-mt-4 py-8" aria-labelledby="titulo-regalos">
        <div class="flex flex-wrap items-start justify-between gap-x-5 gap-y-2">
            <h2 id="titulo-regalos" class="section-arrival text-2xl sm:text-3xl" data-scroll-reveal>¿Qué regalo?</h2>
            <a class="text-sm font-semibold text-brand underline underline-offset-4" href="#como-comprar">Cómo comprar</a>
        </div>

        <div class="mt-5 grid gap-8 lg:grid-cols-[13rem_minmax(0,1fr)]">
            <div class="space-y-5">
                <div>
                    <p class="mb-2 text-sm font-semibold" id="gift-budget">Presupuesto</p>
                    <ul class="shop-filters flex flex-wrap gap-2 lg:flex-col lg:items-start" aria-labelledby="gift-budget">
                        <li><a @class([$budget === null ? 'chip-active' : 'chip']) @if ($budget === null) aria-current="true" @endif href="{{ route('store.home', array_filter(['para' => $audience])) }}#regalos">Todos</a></li>
                        @foreach (StoreCatalog::GIFT_BUDGETS as $option)
                            <li><a @class([$budget === $option ? 'chip-active' : 'chip']) @if ($budget === $option) aria-current="true" @endif href="{{ route('store.home', array_filter(['presupuesto' => $option, 'para' => $audience])) }}#regalos">{{ $option === 250 ? 'Bs 250 en adelante' : 'Hasta Bs '.$option }}</a></li>
                        @endforeach
                    </ul>
                </div>
                @if ($audiences)
                    <div>
                        <p class="mb-2 text-sm font-semibold" id="gift-for">Para quién</p>
                        <ul class="shop-filters flex flex-wrap gap-2" aria-labelledby="gift-for">
                            <li><a @class([$audience === null ? 'chip-active' : 'chip']) @if ($audience === null) aria-current="true" @endif href="{{ route('store.home', array_filter(['presupuesto' => $budget])) }}#regalos">Todos</a></li>
                            @foreach ($audiences as $value => $label)
                                <li><a @class([$audience === $value ? 'chip-active' : 'chip']) @if ($audience === $value) aria-current="true" @endif href="{{ route('store.home', array_filter(['presupuesto' => $budget, 'para' => $value])) }}#regalos">{{ $label }}</a></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

            @if ($gifts->isEmpty())
                <p class="bg-soft p-6 text-sm text-muted">No encontramos regalos con ese filtro. Prueba con otro presupuesto.</p>
            @else
                <ul class="grid grid-cols-2 gap-x-4 gap-y-8 md:grid-cols-3">
                    @foreach ($gifts as $product)
                        <li class="flex min-w-0"><x-store.product-card :product="$product" class="w-full" /></li>
                    @endforeach
                </ul>
            @endif
        </div>
    </section>

    <x-store.shopping-journey />

    {{-- Calendario de ofertas --}}
    <section id="calendario" class="wrap py-8" aria-labelledby="calendar-title">
        <h2 id="calendar-title" class="section-arrival text-2xl sm:text-3xl" data-scroll-reveal>Una oferta por día</h2>
        <p class="mt-2 text-sm text-muted">Del 1 al 24 de diciembre. Las ofertas disponibles se pueden abrir desde el calendario.</p>
        <div class="calendar-countdown" data-countdown="{{ $countdown['at']->toIso8601String() }}">
            <p>{{ $countdown['label'] }}</p>
            <dl>
                @foreach (['days' => 'días', 'hours' => 'horas', 'minutes' => 'min', 'seconds' => 'seg'] as $unit => $label)
                    <div><dt>{{ $label }}</dt><dd data-unit="{{ $unit }}">--</dd></div>
                @endforeach
            </dl>
        </div>

        <ol class="offer-calendar mt-5 grid grid-cols-3 gap-px border-2 border-ink bg-ink sm:grid-cols-6 lg:grid-cols-8">
            @foreach ($doors as $door)
                <li class="relative flex min-h-24 flex-col justify-between gap-1" @if ($door['state'] === 'open' && $door['card']) data-open-door @endif>
                    <span class="font-display text-xl font-bold">{{ $door['day'] }}</span>
                    @if ($door['state'] === 'open' && $door['card'])
                        <a href="{{ route('store.product', $door['card']->slug) }}" class="text-sm leading-snug after:absolute after:inset-0">
                            {{ $door['title'] }}
                            <strong class="block">{{ Money::display($door['card']->priceCents) }}</strong>
                        </a>
                    @else
                        <span class="text-xs text-muted">
                            {{ match ($door['state']) { 'ended' => 'Terminó', 'upcoming' => 'Se abre pronto', default => 'Por anunciar' } }}
                        </span>
                    @endif
                </li>
            @endforeach
        </ol>
    </section>

    {{-- Por mayor --}}
    <section id="por-mayor" class="mt-8 scroll-mt-4 bg-brand text-white">
        <div class="wrap grid gap-8 py-10 md:grid-cols-2">
            <div class="space-y-3">
                <h2 class="section-arrival text-2xl sm:text-3xl" data-scroll-reveal>¿Regalos de empresa o para revender?</h2>
                <p class="max-w-md text-sm text-white/85">El precio por mayor se aplica solo al llevar el mínimo de un mismo código. No hay un monto mínimo de pedido.</p>
                <a href="{{ route('store.wholesale.show') }}" class="btn cut bg-cyan text-ink hover:bg-white">Ver precios por mayor</a>
            </div>
            <dl class="self-center">
                @foreach ($wholesaleMinimums as $minimum)
                    <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-white/25 py-3">
                        <dt class="font-display font-semibold">{{ $minimum->name }}</dt>
                        <dd class="font-mono text-sm">
                            @if ($minimum->min_qty === $minimum->max_qty)
                                desde {{ $minimum->min_qty }} u. del mismo código
                            @else
                                desde {{ $minimum->min_qty }} a {{ $minimum->max_qty }} u. según el modelo
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </section>
</x-layouts.store>
