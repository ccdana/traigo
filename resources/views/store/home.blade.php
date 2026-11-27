@use('App\Services\Store\StoreCatalog')
@use('App\Support\Money')
@use('App\Support\Dates')

<x-layouts.store>
    {{-- Portada --}}
    <section class="wrap grid items-center gap-8 py-10 md:grid-cols-2">
        <div class="space-y-5">
            <p class="eyebrow">Navidad {{ now()->year }} · Cochabamba</p>
            <h1 class="text-4xl leading-tight sm:text-5xl">Regalos que llegan a tiempo</h1>
            <p class="max-w-md text-muted">Tomatodos, macetas y juguetes importados, al detalle o por mayor.</p>

            <div data-countdown="{{ $countdown['at']->toIso8601String() }}">
                <p class="eyebrow mb-2">{{ $countdown['label'] }}</p>
                <dl class="flex gap-4">
                    @foreach (['days' => 'días', 'hours' => 'horas', 'minutes' => 'min', 'seconds' => 'seg'] as $unit => $label)
                        <div class="flex flex-col-reverse">
                            <dt class="eyebrow">{{ $label }}</dt>
                            <dd class="font-display text-3xl font-bold tabular-nums" data-unit="{{ $unit }}">--</dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            <div class="flex flex-wrap gap-3">
                <a href="#regalos" class="btn-primary">Ver regalos</a>
                <a href="#por-mayor" class="btn-soft">Compra por mayor →</a>
            </div>
        </div>

        <div class="cut flex aspect-4/3 items-center justify-center bg-cyan" aria-hidden="true">
            <svg viewBox="0 0 240 200" class="h-5/6 w-5/6">
                <path d="M120 24 78 84h24L62 134h28l-42 48h144l-42-48h28l-40-50h24z" fill="#5b12f5" stroke="#150b3d" stroke-width="3" stroke-linejoin="round"/>
                <path d="m120 6 5 12 13 1-10 8 3 13-11-7-11 7 3-13-10-8 13-1z" fill="#fff" stroke="#150b3d" stroke-width="3" stroke-linejoin="round"/>
                <g fill="#fff"><circle cx="112" cy="72" r="5"/><circle cx="134" cy="108" r="5"/><circle cx="100" cy="132" r="5"/><circle cx="148" cy="156" r="5"/><circle cx="92" cy="166" r="5"/></g>
                <g stroke="#150b3d" stroke-width="3"><rect x="26" y="150" width="42" height="34" fill="#d9c8ff"/><path d="M47 150v34M26 162h42" fill="none"/><rect x="176" y="142" width="40" height="42" fill="#fff"/><path d="M196 142v42M176 158h40" fill="none"/></g>
            </svg>
        </div>
    </section>

    {{-- Calendario de ofertas --}}
    <section class="wrap py-8" aria-labelledby="calendario">
        <h2 id="calendario" class="text-2xl sm:text-3xl">Una oferta por día</h2>
        <p class="eyebrow mt-1">Del 1 al 24 de diciembre se abre una puerta cada día</p>

        <ol class="mt-5 grid grid-cols-3 gap-px border-2 border-ink bg-ink sm:grid-cols-6 lg:grid-cols-8">
            @foreach ($doors as $door)
                <li @class(['relative flex min-h-24 flex-col justify-between gap-1 p-2', 'bg-brand text-white' => $door['state'] === 'open' && $door['card'], 'bg-white' => ! ($door['state'] === 'open' && $door['card'])])>
                    <span class="font-display text-xl font-bold">{{ $door['day'] }}</span>
                    @if ($door['state'] === 'open' && $door['card'])
                        <a href="{{ route('store.product', $door['card']->slug) }}" class="text-xs leading-snug after:absolute after:inset-0">
                            {{ $door['title'] }}
                            <strong class="block">{{ Money::display($door['card']->priceCents) }}</strong>
                        </a>
                    @else
                        <span class="eyebrow">
                            {{ match ($door['state']) { 'ended' => 'Terminó', 'upcoming' => 'Se abre pronto', default => 'Pronto' } }}
                        </span>
                    @endif
                </li>
            @endforeach
        </ol>
    </section>

    {{-- Guía de regalos --}}
    <section id="regalos" class="wrap scroll-mt-4 py-8" aria-labelledby="titulo-regalos">
        <h2 id="titulo-regalos" class="text-2xl sm:text-3xl">¿Qué regalo?</h2>

        <div class="mt-5 grid gap-8 lg:grid-cols-[13rem_minmax(0,1fr)]">
            <div class="space-y-5">
                <div>
                    <p class="eyebrow mb-2">Presupuesto</p>
                    <ul class="flex flex-wrap gap-2 lg:flex-col lg:items-start">
                        <li><a @class([$budget === null ? 'chip-active' : 'chip']) href="{{ route('store.home', array_filter(['para' => $audience])) }}#regalos">Todos</a></li>
                        @foreach (StoreCatalog::GIFT_BUDGETS as $option)
                            <li><a @class([$budget === $option ? 'chip-active' : 'chip']) href="{{ route('store.home', array_filter(['presupuesto' => $option, 'para' => $audience])) }}#regalos">Hasta Bs {{ $option }}</a></li>
                        @endforeach
                    </ul>
                </div>
                @if ($audiences)
                    <div>
                        <p class="eyebrow mb-2">Para quién</p>
                        <ul class="flex flex-wrap gap-2">
                            <li><a @class([$audience === null ? 'chip-active' : 'chip']) href="{{ route('store.home', array_filter(['presupuesto' => $budget])) }}#regalos">Todos</a></li>
                            @foreach ($audiences as $value => $label)
                                <li><a @class([$audience === $value ? 'chip-active' : 'chip']) href="{{ route('store.home', array_filter(['presupuesto' => $budget, 'para' => $value])) }}#regalos">{{ $label }}</a></li>
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

    {{-- Categorías --}}
    <section class="wrap py-8" aria-labelledby="catalogo">
        <h2 id="catalogo" class="text-2xl sm:text-3xl">Todo el catálogo</h2>
        <ul class="mt-5 grid gap-4 sm:grid-cols-3">
            @foreach ($categories as $category)
                <li>
                    <a href="{{ route('store.category', $category->slug) }}" class="group block">
                        <x-store.product-art :category="$category->slug" />
                        <span class="mt-2 flex items-baseline justify-between gap-2 border-b-2 border-ink pb-2">
                            <span class="font-display text-lg font-bold group-hover:text-brand">{{ $category->name }}</span>
                            <span class="eyebrow">{{ $category->products_count }} productos</span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- Por mayor --}}
    <section id="por-mayor" class="mt-8 scroll-mt-4 bg-brand text-white">
        <div class="wrap grid gap-8 py-10 md:grid-cols-2">
            <div class="space-y-3">
                <h2 class="text-2xl sm:text-3xl">¿Regalos de empresa o para revender?</h2>
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

    {{-- Entrega y pago --}}
    <section class="wrap py-10" aria-labelledby="a-tiempo">
        <h2 id="a-tiempo" class="text-2xl">Pide a tiempo</h2>
        <ul class="mt-5 grid gap-6 sm:grid-cols-3">
            <li class="border-t-2 border-ink pt-3">
                <h3 class="text-base">Delivery en Cochabamba</h3>
                <p class="mt-1 text-sm text-muted">Coordinamos la entrega por WhatsApp.</p>
            </li>
            <li class="border-t-2 border-ink pt-3">
                <h3 class="text-base">Recojo en el punto</h3>
                <p class="mt-1 text-sm text-muted">Recoge tu pedido cuando esté listo.</p>
            </li>
            <li class="border-t-2 border-ink pt-3">
                <h3 class="text-base">Pago con QR</h3>
                <p class="mt-1 text-sm text-muted">Pagas desde la app de tu banco y subes el comprobante.</p>
            </li>
        </ul>
    </section>
</x-layouts.store>
