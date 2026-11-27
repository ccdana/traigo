@use('App\Support\Money')
@use('Illuminate\Support\Str')

<x-layouts.store title="Confirma tu pedido">
    <div class="wrap py-6">
        <x-breadcrumb :trail="['Inicio' => route('store.home'), 'Bolsa' => route('store.bag.show')]" current="Pago" />
        <h1 class="mt-3 text-4xl">Confirma tu pedido</h1>
        <x-flash class="mt-4" />

        <form method="POST" action="{{ route('store.checkout.store') }}" data-once class="mt-6 grid items-start gap-8 lg:grid-cols-[minmax(0,1fr)_22rem]">
            @csrf
            <input type="hidden" name="expected_total" value="{{ $expectedTotal }}">
            <input type="hidden" name="client_uuid" value="{{ old('client_uuid', (string) Str::uuid()) }}">

            <div class="space-y-8">
                <fieldset class="border-t-2 border-ink pt-3">
                    <legend class="contents"><h2 class="text-xl"><span class="font-mono text-xs text-brand">01</span> Tus datos</h2></legend>
                    <dl class="mt-4 grid gap-3 bg-soft p-4 text-sm sm:grid-cols-2">
                        <div><dt class="eyebrow">Nombre</dt><dd class="font-medium">{{ $account->name }} {{ $account->lastname }}</dd></div>
                        <div><dt class="eyebrow">WhatsApp</dt><dd class="font-medium">{{ $account->phone }}</dd></div>
                    </dl>
                    <a href="{{ route('store.account.edit') }}" class="mt-2 inline-block text-sm text-brand underline">Cambiar mis datos</a>
                </fieldset>

                <fieldset class="border-t-2 border-ink pt-3" data-delivery>
                    <legend class="contents"><h2 class="text-xl"><span class="font-mono text-xs text-brand">02</span> Entrega</h2></legend>
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach (['pickup' => 'Recojo en el punto', 'delivery' => 'Delivery'] as $value => $label)
                            <label class="cursor-pointer">
                                <input type="radio" name="delivery_type" value="{{ $value }}" class="peer sr-only" @checked(old('delivery_type', 'pickup') === $value)>
                                <span class="chip px-4 py-2.5 text-sm peer-checked:bg-ink peer-checked:text-white peer-focus-visible:outline-2 peer-focus-visible:outline-brand">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    <div class="mt-4" data-delivery-address>
                        <label for="address" class="eyebrow">Dirección de entrega (solo delivery)</label>
                        <input id="address" name="address" value="{{ old('address', $lastAddress) }}" maxlength="300" autocomplete="street-address" class="field mt-1">
                        <p class="mt-2 text-xs text-muted">El delivery se coordina por WhatsApp. {{ $deliveryNote ? "Costo de envío: {$deliveryNote}." : 'El costo de envío se confirma aparte.' }}</p>
                    </div>
                </fieldset>

                <section class="border-t-2 border-ink pt-3">
                    <h2 class="text-xl"><span class="font-mono text-xs text-brand">03</span> Pago</h2>
                    <p class="chip-active mt-4 py-2.5 text-sm">QR bancario</p>
                    <p class="mt-3 text-sm text-muted">Al confirmar te mostramos el código. Pagas desde la app de tu banco y subes el comprobante para que lo confirmemos.</p>
                </section>
            </div>

            <aside class="cut bg-soft p-5" aria-labelledby="tu-pedido">
                <h2 id="tu-pedido" class="eyebrow text-brand">Tu pedido</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach ($lines as $line)
                        <li class="flex justify-between gap-3">
                            <span>{{ $line['product']->name }} × {{ $line['quantity'] }}</span>
                            <span class="font-mono font-semibold whitespace-nowrap">{{ Money::display($line['total_cents']) }}</span>
                        </li>
                    @endforeach
                </ul>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-3 text-muted"><dt>Envío</dt><dd class="font-mono">se coordina aparte</dd></div>
                    <div class="flex items-baseline justify-between gap-3 border-t-2 border-ink pt-3">
                        <dt class="font-display font-bold">Total</dt>
                        <dd class="font-display text-2xl font-bold">{{ Money::display($subtotal_cents) }}</dd>
                    </div>
                </dl>
                <button type="submit" class="btn-primary mt-4 w-full">Confirmar pedido · {{ Money::display($subtotal_cents) }}</button>
                <p class="mt-3 text-xs text-muted">Reservamos tu pedido por {{ $hours }} horas. Si no pagas a tiempo, vence y el stock vuelve a la tienda.</p>
            </aside>
        </form>
    </div>
</x-layouts.store>
