<x-layouts.staff title="Ingresar">
    <div class="mx-auto max-w-sm bg-white p-6">
        <h1 class="text-2xl">Ingresar</h1>
        <p class="mt-1 text-sm text-muted">Con el mismo código y PIN de la app.</p>

        <x-flash class="mt-4" />

        <form method="POST" action="{{ route('staff.login.store') }}" class="mt-5 space-y-4">
            @csrf
            <div>
                <label for="code" class="eyebrow">Código</label>
                <input id="code" name="code" value="{{ old('code') }}" required maxlength="20" autocomplete="username" autocapitalize="characters" class="field mt-1 uppercase" placeholder="ISA-03">
            </div>
            <div>
                <label for="pin" class="eyebrow">PIN</label>
                <input id="pin" type="password" name="pin" required maxlength="12" inputmode="numeric" autocomplete="current-password" class="field mt-1">
            </div>
            <button type="submit" class="btn-primary w-full">Ingresar</button>
        </form>
    </div>
</x-layouts.staff>
