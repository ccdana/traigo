<x-layouts.staff title="Ingresar">
    <div class="admin-login">
        <h1>Administración</h1>
        <p>Ingresa con la clave de Dirección.</p>

        <x-flash class="mt-4" />

        <form method="POST" action="{{ route('staff.login.store') }}" class="mt-5 space-y-4">
            @csrf
            <div>
                <label for="staff-password" class="block text-sm font-semibold">Clave de Dirección</label>
                <input id="staff-password" type="password" name="password" required maxlength="12" inputmode="numeric" autocomplete="current-password" class="field mt-1">
            </div>
            <button type="submit" class="btn-primary w-full">Ingresar</button>
        </form>
        <a href="{{ route('store.home') }}" class="admin-login-return">Volver a la tienda</a>
    </div>
</x-layouts.staff>
