<x-layouts.store title="Contraseña nueva">
    <div class="wrap py-8">
        <div class="mx-auto max-w-sm">
            <h1 class="text-3xl">Pon tu contraseña nueva</h1>
            <x-flash class="mt-4" />

            <form method="POST" action="{{ route('store.password.update') }}" data-once class="mt-6 space-y-4">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <x-field name="email" label="Correo" type="email" :value="is_string($email) ? $email : null" required maxlength="150" autocomplete="email" />
                <x-field name="password" label="Contraseña nueva" type="password" required minlength="8" autocomplete="new-password" hint="Al menos 8 caracteres." />
                <x-field name="password_confirmation" label="Repite la contraseña" type="password" required minlength="8" autocomplete="new-password" />
                <button type="submit" class="btn-primary w-full">Guardar contraseña</button>
            </form>
            <a href="{{ route('store.password.request') }}" class="mt-3 inline-block text-sm text-muted underline">Pedir otro enlace</a>
        </div>
    </div>
</x-layouts.store>
