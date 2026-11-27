@use('App\Support\Money')

<x-layouts.staff title="Nueva oferta">
    <x-breadcrumb :trail="['Ofertas' => route('staff.promotions.index')]" current="Nueva" />
    <h1 class="mt-3 text-3xl">Nueva oferta</h1>
    <x-flash class="mt-4" />

    <form method="POST" action="{{ route('staff.promotions.store') }}" data-once class="mt-4 max-w-3xl space-y-4 bg-white p-5">
        @csrf
        <x-field name="name" label="Nombre (se ve en el calendario)" required maxlength="80" />
        <x-field name="description" label="Descripción (opcional)" maxlength="500" />

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="discount_type" class="eyebrow">Tipo de descuento</label>
                <select id="discount_type" name="discount_type" class="field mt-1">
                    @foreach ($types as $value => $label)
                        <option value="{{ $value }}" @selected(old('discount_type') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <x-field name="discount_value" label="Valor (% o Bs, según el tipo)" required inputmode="decimal" />
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <x-field name="starts_at" label="Empieza" type="datetime-local" required />
            <x-field name="ends_at" label="Termina" type="datetime-local" />
            <x-field name="calendar_day" label="Puerta (1 a 24, opcional)" type="number" min="1" max="24" hint="Una puerta lleva un solo producto y fecha de fin." />
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="applies_to_wholesale" value="1" @checked(old('applies_to_wholesale')) class="size-4 accent-brand">
            También se aplica a compras por mayor
        </label>

        <fieldset>
            <legend class="eyebrow">Productos</legend>
            <label for="filtro-productos" class="sr-only">Buscar producto</label>
            <input id="filtro-productos" type="search" data-filter="#lista-productos" placeholder="Buscar por nombre o ITEM" class="field mt-1">
            <ul id="lista-productos" class="mt-2 max-h-80 overflow-y-auto border border-line">
                @foreach ($products as $product)
                    <li>
                        <label class="flex cursor-pointer items-center gap-2 border-b border-line px-3 py-2 text-sm hover:bg-soft">
                            <input type="checkbox" name="product_ids[]" value="{{ $product->id_product }}" @checked(in_array($product->id_product, old('product_ids', []), true)) class="size-4 accent-brand">
                            <span class="min-w-0 flex-1">{{ $product->name }} <span class="eyebrow">{{ $product->item_code }}</span></span>
                            <span class="font-mono text-xs whitespace-nowrap">{{ Money::display(Money::toCents($product->price_unit_bs)) }}@if ($product->price_wholesale_bs !== null) · mín. {{ Money::display(Money::toCents($product->price_wholesale_bs)) }}@endif</span>
                        </label>
                    </li>
                @endforeach
            </ul>
        </fieldset>

        <button type="submit" class="btn-primary">Enviar a Dirección</button>
    </form>
</x-layouts.staff>
