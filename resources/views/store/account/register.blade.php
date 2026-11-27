<x-layouts.store title="Crear cuenta">
    <div class="wrap py-8">
        <div class="mx-auto max-w-lg">
            <h1 class="text-4xl">Crea tu cuenta</h1>
            <p class="mt-1 text-sm text-muted">Solo pedimos lo necesario para tu compra. ¿Ya tienes cuenta? <a href="{{ route('store.login') }}" class="font-medium text-brand underline">Ingresa</a></p>
            <x-flash class="mt-4" />

            <form method="POST" action="{{ route('store.register.store') }}" data-once class="mt-6 space-y-4">
                @csrf
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field name="name" label="Nombre" required maxlength="80" autocomplete="given-name" />
                    <x-field name="lastname" label="Apellido" required maxlength="80" autocomplete="family-name" />
                </div>
                <x-field name="phone" label="WhatsApp" type="tel" required maxlength="20" autocomplete="tel" inputmode="tel" hint="Por aquí coordinamos tu entrega." />
                <x-field name="email" label="Correo" type="email" required maxlength="150" autocomplete="email" hint="Con él ingresas y recuperas tu contraseña." />
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field name="password" label="Contraseña" type="password" required minlength="8" autocomplete="new-password" hint="Al menos 8 caracteres." />
                    <x-field name="password_confirmation" label="Repite la contraseña" type="password" required minlength="8" autocomplete="new-password" />
                </div>
                <button type="submit" class="btn-primary w-full">Crear cuenta</button>
                <p class="text-xs text-muted">Usamos tus datos solo para gestionar tus pedidos.</p>
            </form>
        </div>
    </div>
</x-layouts.store>
