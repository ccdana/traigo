<x-layouts.store title="Recuperar contraseña">
    <div class="wrap py-8">
        <div class="mx-auto max-w-sm">
            <h1 class="text-3xl">Recupera tu contraseña</h1>
            <p class="mt-1 text-sm text-muted">Escribe tu correo y te enviamos un enlace para poner una nueva.</p>
            <x-flash class="mt-4" />

            <form method="POST" action="{{ route('store.password.email') }}" data-once class="mt-6 space-y-4">
                @csrf
                <x-field name="email" label="Correo" type="email" required maxlength="150" autocomplete="email" />
                <button type="submit" class="btn-primary w-full">Enviar enlace</button>
            </form>
            <a href="{{ route('store.login') }}" class="mt-3 inline-block text-sm text-muted underline">Volver a ingresar</a>
        </div>
    </div>
</x-layouts.store>
