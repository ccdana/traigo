<x-layouts.store title="Confirma tu correo">
    <div class="wrap py-8">
        <div class="mx-auto max-w-md">
            <h1 class="text-4xl">Confirma tu correo</h1>
            <p class="mt-3 text-sm text-muted">Enviamos un código de seis dígitos a <strong class="wrap-anywhere text-ink">{{ $email }}</strong>. Úsalo para terminar de crear tu cuenta.</p>
            <x-flash class="mt-4" />

            <form method="POST" action="{{ route('store.register.verify') }}" data-once class="mt-6 space-y-4">
                @csrf
                <x-field name="code" label="Código de verificación" required minlength="6" maxlength="6" pattern="[0-9]{6}" autocomplete="one-time-code" inputmode="numeric" hint="Copia los seis dígitos del correo." />
                <button type="submit" class="btn-primary w-full">Confirmar y crear cuenta</button>
            </form>

            <form method="POST" action="{{ route('store.register.resend') }}" data-once class="mt-4">
                @csrf
                <button type="submit" class="btn-soft w-full">Enviar otro código</button>
            </form>
            <p class="mt-4 text-sm text-muted">Si no encuentras el correo, revisa también la carpeta de spam. <a href="{{ route('store.register.create') }}" class="font-semibold text-brand underline">Usar otro correo</a></p>
        </div>
    </div>
</x-layouts.store>
