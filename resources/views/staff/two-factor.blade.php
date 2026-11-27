<x-layouts.staff title="Verificar">
    <div class="mx-auto max-w-sm bg-white p-6">
        <h1 class="text-2xl">Revisa tu correo</h1>
        <p class="mt-1 text-sm text-muted">Te enviamos un código de 6 números. Vence en pocos minutos y sirve una sola vez.</p>

        <x-flash class="mt-4" />

        <form method="POST" action="{{ route('staff.two-factor.verify') }}" class="mt-5 space-y-4">
            @csrf
            <div>
                <label for="code" class="eyebrow">Código</label>
                <input id="code" name="code" required maxlength="6" inputmode="numeric" autocomplete="one-time-code" class="field mt-1 text-center font-mono text-xl tracking-widest">
            </div>
            <button type="submit" class="btn-primary w-full">Verificar</button>
        </form>

        <form method="POST" action="{{ route('staff.two-factor.resend') }}" class="mt-3">
            @csrf
            <button type="submit" class="cursor-pointer text-sm font-medium text-brand underline">Enviar otro código</button>
        </form>
        <a href="{{ route('staff.login') }}" class="mt-2 inline-block text-sm text-muted underline">Volver</a>
    </div>
</x-layouts.staff>
