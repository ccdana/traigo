<x-layouts.store title="Mi cuenta">
    <div class="wrap py-6">
        <x-breadcrumb :trail="['Inicio' => route('store.home')]" current="Mi cuenta" />
        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-4xl">Mi cuenta</h1>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('store.order.index') }}" class="btn-dark">Mis pedidos</a>
                <form method="POST" action="{{ route('store.logout') }}">
                    @csrf
                    <button type="submit" class="btn-soft">Cerrar sesión</button>
                </form>
            </div>
        </div>
        <x-flash class="mt-4" />

        <div class="mt-6 grid items-start gap-8 md:grid-cols-2">
            <section class="border-t-2 border-ink pt-3" aria-labelledby="mis-datos">
                <h2 id="mis-datos" class="text-xl">Mis datos</h2>
                <form method="POST" action="{{ route('store.account.update') }}" data-once class="mt-4 space-y-4">
                    @csrf @method('PATCH')
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-field name="name" label="Nombre" :value="$account->name" required maxlength="80" autocomplete="given-name" />
                        <x-field name="lastname" label="Apellido" :value="$account->lastname" required maxlength="80" autocomplete="family-name" />
                    </div>
                    <x-field name="phone" label="WhatsApp" type="tel" :value="$account->phone" required maxlength="20" autocomplete="tel" />
                    <x-field name="email" label="Correo" type="email" :value="$account->email" required maxlength="150" autocomplete="email" />
                    <x-field name="current_password" label="Contraseña actual (solo si cambias el correo)" type="password" autocomplete="current-password" />
                    <button type="submit" class="btn-primary">Guardar datos</button>
                </form>
            </section>

            <section class="border-t-2 border-ink pt-3" aria-labelledby="mi-clave">
                <h2 id="mi-clave" class="text-xl">Cambiar contraseña</h2>
                <form method="POST" action="{{ route('store.account.password') }}" data-once class="mt-4 space-y-4">
                    @csrf @method('PUT')
                    <x-field name="current_password" id="clave-actual" label="Contraseña actual" type="password" required autocomplete="current-password" />
                    <x-field name="password" label="Contraseña nueva" type="password" required minlength="8" autocomplete="new-password" />
                    <x-field name="password_confirmation" label="Repite la contraseña nueva" type="password" required minlength="8" autocomplete="new-password" />
                    <button type="submit" class="btn-dark">Cambiar contraseña</button>
                </form>
            </section>
        </div>
    </div>
</x-layouts.store>
