<x-layouts.staff title="Nuevo producto">
    <x-breadcrumb :trail="['Productos' => route('staff.products.index')]" current="Nuevo" />
    <h1 class="mt-3 text-3xl">Nuevo producto</h1>
    <p class="mt-1 text-sm text-muted">Nace fuera de la venta: Dirección lo publica. El stock empieza en 0 y lo carga almacén.</p>
    <x-flash class="mt-4" />

    <form method="POST" action="{{ route('staff.products.store') }}" data-once class="mt-4 max-w-2xl space-y-4 bg-white p-5">
        @csrf
        <x-field name="item_code" label="ITEM (código del negocio)" required maxlength="40" class="uppercase" />
        <x-staff.product-details :categories="$categories" />

        <div class="grid gap-4 sm:grid-cols-3">
            <x-field name="price_unit_bs" label="Precio por unidad (Bs)" required inputmode="decimal" />
            <x-field name="price_wholesale_bs" label="Precio por mayor (Bs)" inputmode="decimal" />
            <x-field name="wholesale_min_qty" label="Mínimo por mayor" type="number" min="2" />
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <x-field name="cost_bs" label="Costo (Bs, opcional)" inputmode="decimal" hint="No se muestra en la tienda." />
            <x-field name="commission_pct" label="Comisión % (opcional)" inputmode="decimal" />
        </div>

        <button type="submit" class="btn-primary">Crear producto</button>
    </form>
</x-layouts.staff>
