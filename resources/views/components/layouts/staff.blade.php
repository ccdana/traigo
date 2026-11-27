@props(['title'])

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} · Panel Traigo</title>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-soft">
    <a href="#contenido" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:bg-ink focus:px-4 focus:py-2 focus:text-white">Saltar al contenido</a>

    <header class="bg-ink text-white">
        <div class="wrap flex flex-wrap items-center justify-between gap-3 py-3">
            <a href="{{ route('staff.dashboard') }}" class="font-display text-xl font-bold tracking-wide">TRAIGO <span class="font-mono text-[11px] font-medium text-cyan uppercase">Administración</span></a>
            @auth
                <div class="flex flex-wrap items-center gap-4 text-sm">
                    @php
                        $role = auth()->user()->role;
                        $menu = array_filter([
                            'staff.dashboard' => 'Estadísticas',
                            'staff.inventory.index' => 'Inventario',
                            'staff.products.index' => $role->canManageCatalog() ? 'Productos' : null,
                            'staff.promotions.index' => $role->canManageCatalog() ? 'Ofertas' : null,
                            'staff.quotes.index' => 'Cotizaciones',
                            'staff.authorizations.index' => $role->canAuthorize() ? 'Autorizaciones' : null,
                            'staff.settings.edit' => $role->canManageCatalog() ? 'Configuración' : null,
                        ]);
                    @endphp
                    <nav aria-label="Panel" class="flex flex-wrap gap-4">
                        @foreach ($menu as $name => $label)
                            @php($active = $name === 'staff.dashboard' ? request()->routeIs($name) : request()->routeIs(\Illuminate\Support\Str::beforeLast($name, '.').'.*'))
                            <a href="{{ route($name) }}" @class(['font-medium hover:text-cyan', 'text-cyan' => $active]) @if ($active) aria-current="page" @endif>{{ $label }}</a>
                        @endforeach
                    </nav>
                    <span class="text-white/70">{{ auth()->user()->name }} · {{ auth()->user()->title }}</span>
                    <form method="POST" action="{{ route('staff.logout') }}">
                        @csrf
                        <button type="submit" class="cursor-pointer underline hover:text-cyan">Salir</button>
                    </form>
                </div>
            @endauth
        </div>
    </header>

    <main id="contenido" class="wrap py-6">
        {{ $slot }}
    </main>
</body>
</html>
