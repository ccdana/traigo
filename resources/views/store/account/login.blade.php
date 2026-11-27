<x-layouts.store title="Ingresar">
    <div class="wrap py-8">
        <div class="mx-auto max-w-sm">
            <h1 class="text-4xl">Ingresa</h1>
            <p class="mt-1 text-sm text-muted">Necesitas tu cuenta para comprar y ver tus pedidos.</p>
            <x-flash class="mt-4" />

            <form method="POST" action="{{ route('store.login.store') }}" data-once class="mt-6 space-y-4">
                @csrf
                <x-field name="email" label="Correo" type="email" required maxlength="150" autocomplete="email" />
                <x-field name="password" label="Contraseña" type="password" required autocomplete="current-password" />
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="remember" value="1" @checked(old('remember')) class="size-4 accent-brand">
                    Mantener mi sesión iniciada
                </label>
                <button type="submit" class="btn-primary w-full">Ingresar</button>
            </form>

            <p class="mt-3 text-sm"><a href="{{ route('store.password.request') }}" class="text-brand underline">Olvidé mi contraseña</a></p>

            <div class="mt-6 border-t-2 border-ink pt-4">
                <p class="text-sm">¿Primera vez en Traigo?</p>
                <a href="{{ route('store.register.create') }}" class="btn-dark mt-3 w-full">Crear cuenta</a>
            </div>
        </div>
    </div>
</x-layouts.store>
