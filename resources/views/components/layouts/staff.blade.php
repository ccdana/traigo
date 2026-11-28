@props(['title'])

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="{{ asset('brand/traigo-mark.png') }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} · Panel Traigo</title>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="admin-shell min-h-screen bg-soft @auth admin-authenticated @endauth">
    <a href="#contenido" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:bg-ink focus:px-4 focus:py-2 focus:text-white">Saltar al contenido</a>

    <header class="admin-header">
        <div class="admin-header-inner">
            <a href="{{ route('staff.dashboard') }}" class="admin-brand" aria-label="TRAIGO Administración">
                <img src="{{ asset('brand/traigo-white.png') }}" alt="TRAIGO" width="683" height="186">
                <span>Panel de Dirección</span>
            </a>
            @auth
                @php
                    $staffUser = auth()->user();
                    $role = $staffUser->role;
                    $groups = [
                        'Resumen' => ['staff.dashboard' => 'Estadísticas'],
                        'Operación' => array_filter([
                            'staff.inventory.index' => 'Inventario',
                            'staff.products.index' => $role->canManageCatalog() ? 'Productos' : null,
                            'staff.promotions.index' => $role->canManageCatalog() ? 'Ofertas' : null,
                            'staff.quotes.index' => 'Cotizaciones',
                        ]),
                        'Gestión' => array_filter([
                            'staff.authorizations.index' => $role->canAuthorize() ? 'Autorizaciones' : null,
                            'staff.settings.edit' => $role->canManageCatalog() ? 'Configuración' : null,
                        ]),
                    ];
                @endphp
                <nav aria-label="Panel" class="admin-navigation">
                    @foreach (array_filter($groups) as $group => $menu)
                        <div class="admin-nav-group">
                            <p>{{ $group }}</p>
                            @foreach ($menu as $name => $label)
                            @php($active = $name === 'staff.dashboard' ? request()->routeIs($name) : request()->routeIs(\Illuminate\Support\Str::beforeLast($name, '.').'.*'))
                                <a href="{{ route($name) }}" @if ($active) aria-current="page" @endif>{{ $label }}</a>
                            @endforeach
                        </div>
                    @endforeach
                </nav>
                <div class="admin-session">
                    <span>{{ $staffUser->name }}</span>
                    <form method="POST" action="{{ route('staff.logout') }}">
                        @csrf
                        <button type="submit">Salir</button>
                    </form>
                </div>
            @endauth
        </div>
    </header>

    <main id="contenido" class="admin-content">
        {{ $slot }}
    </main>
</body>
</html>
