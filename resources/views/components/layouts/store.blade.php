@props(['title' => null, 'description' => 'Tomatodos, macetas y juguetes importados. Compra al detalle o por mayor en Cochabamba.'])

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="{{ asset('brand/traigo-mark.png') }}">
    <title>{{ $title ? "{$title} · Traigo" : 'Traigo · Regalos que llegan a tiempo' }}</title>
    <meta name="description" content="{{ $description }}">
    <meta property="og:title" content="{{ $title ?? 'Traigo' }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:type" content="website">
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="store-shell flex min-h-screen flex-col">
    <a href="#contenido" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:bg-ink focus:px-4 focus:py-2 focus:text-white">Saltar al contenido</a>

    <div class="bg-brand text-white">
        <div class="wrap flex flex-wrap justify-between gap-x-6 py-1.5 font-mono text-[11px] font-medium tracking-wide uppercase">
            <p>
                Envíos a tiempo para Nochebuena
                @if ($deadlineLabel) · Pide antes del {{ $deadlineLabel }} @endif
            </p>
            <p class="flex gap-3">
                @if ($whatsappUrl)<a href="{{ $whatsappUrl }}" class="hover:underline" rel="noopener" target="_blank">WhatsApp {{ $whatsappNumber }}</a>@endif
                @if ($tiktok)<span class="normal-case">TikTok {{ $tiktok }}</span>@endif
            </p>
        </div>
    </div>

    <header class="store-header border-b border-line">
        <div class="wrap store-header-inner">
            <x-brand-logo />

            <div class="store-header-actions flex items-center gap-3">
                @if ($customerName)
                    <a href="{{ route('store.account.edit') }}" class="store-account-link truncate text-sm font-medium hover:text-brand">Hola, {{ $customerName }}</a>
                @else
                    <a href="{{ route('store.login') }}" class="text-sm font-medium hover:text-brand">Ingresar</a>
                @endif
                <a href="{{ route('store.bag.show') }}" class="btn-primary py-2.5" data-cart-count="{{ $bagCount }}">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M2 3h3l3 12h11l3-9H6M9 19h.01M18 19h.01" stroke-linecap="round"/><circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/></svg>
                    Carrito · {{ $bagCount }}
                </a>
            </div>

            <button type="button" class="nav-toggle btn-soft" aria-expanded="false" aria-controls="store-navigation" hidden data-nav-toggle>Explorar tienda <span aria-hidden="true">+</span></button>
            <nav id="store-navigation" aria-label="Categorías" class="store-navigation">
                <ul class="flex flex-wrap gap-x-5 gap-y-1 text-sm font-medium whitespace-nowrap">
                    @foreach ($navCategories as $item)
                        @php($active = request()->routeIs('store.category') && request()->route('slug') === $item->slug)
                        <li>
                            <a href="{{ route('store.category', $item->slug) }}"
                               @class(['block border-b-2 py-1 hover:text-brand', 'border-brand text-brand' => $active, 'border-transparent' => ! $active])
                               @if ($active) aria-current="page" @endif>{{ $item->name }}</a>
                        </li>
                    @endforeach
                    @foreach (['store.wholesale.show' => 'Por mayor', 'store.order.index' => 'Mis pedidos'] as $name => $label)
                        <li>
                            <a href="{{ route($name) }}"
                               @class(['block border-b-2 py-1 hover:text-brand', 'border-brand text-brand' => request()->routeIs($name), 'border-transparent' => ! request()->routeIs($name)])
                               @if (request()->routeIs($name)) aria-current="page" @endif>{{ $label }}</a>
                        </li>
                    @endforeach
                </ul>
            </nav>
        </div>
    </header>

    <main id="contenido" class="flex-1" tabindex="-1">
        {{ $slot }}
    </main>

    <footer class="mt-16 bg-brand text-white">
        <div class="wrap grid gap-8 py-10 sm:grid-cols-2 lg:grid-cols-4">
            <div class="space-y-3">
                <x-brand-logo tone="light" />
                <p class="max-w-xs text-sm text-white/85">Tomatodos, macetas y juguetes importados. Compra al detalle o por mayor.</p>
            </div>
            <div>
                <h2 class="eyebrow mb-3 text-white/70">Comprar</h2>
                <ul class="space-y-1.5 text-sm">
                    @foreach ($navCategories as $item)
                        <li><a class="hover:underline" href="{{ route('store.category', $item->slug) }}">{{ $item->name }}</a></li>
                    @endforeach
                    <li><a class="hover:underline" href="{{ route('store.wholesale.show') }}">Por mayor</a></li>
                </ul>
            </div>
            <div>
                <h2 class="eyebrow mb-3 text-white/70">Entrega</h2>
                <ul class="space-y-1.5 text-sm">
                    <li>Delivery en Cochabamba</li>
                    <li>Recojo en el punto</li>
                    @if ($pickupAddress)<li>{{ $pickupAddress }}</li>@endif
                </ul>
            </div>
            <div>
                <h2 class="eyebrow mb-3 text-white/70">Pago</h2>
                <ul class="space-y-1.5 text-sm">
                    <li>QR bancario</li>
                    <li><a href="{{ route('staff.login') }}" class="hover:underline">Administración</a></li>
                </ul>
            </div>
        </div>
        <div class="wrap">
            <p class="border-t border-white/25 py-4 font-mono text-[11px] tracking-wide uppercase">Precios en bolivianos · pueden cambiar sin aviso</p>
        </div>
    </footer>
</body>
</html>
