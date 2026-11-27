@use('App\Support\Money')

<x-layouts.store title="Compra por mayor" description="Precios por mayor en tomatodos, macetas y juguetes. Mínimos por código, sin monto mínimo de pedido.">
    <div class="wrap py-6">
        <x-breadcrumb :trail="['Inicio' => route('store.home')]" current="Por mayor" />
        <h1 class="mt-3 text-4xl">Compra por mayor</h1>
        <p class="mt-2 max-w-2xl text-sm text-muted">Para regalos de empresa o para revender. El precio por mayor se aplica solo al llevar el mínimo de unidades de un mismo código. No hay un monto mínimo de pedido.</p>

        <div class="mt-6 grid gap-6 md:grid-cols-3">
            @foreach ($tables as $category => $rows)
                <section class="border-t-2 border-ink pt-3" aria-labelledby="minimo-{{ $loop->index }}">
                    <h2 id="minimo-{{ $loop->index }}" class="text-xl">{{ $category }}</h2>
                    <table class="mt-2 w-full text-sm">
                        <thead class="sr-only"><tr><th>Precio por unidad</th><th>Mínimo del mismo código</th></tr></thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr class="border-b border-line">
                                    <td class="py-2 text-muted">
                                        @if ($rows->count() === 1)
                                            Todos los modelos
                                        @elseif ($row->low === $row->high)
                                            De {{ Money::display(Money::toCents($row->low)) }}
                                        @else
                                            De {{ Money::display(Money::toCents($row->low)) }} a {{ Money::display(Money::toCents($row->high)) }}
                                        @endif
                                    </td>
                                    <td class="py-2 text-right font-mono font-semibold">{{ $row->min_qty }} u.</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </section>
            @endforeach
        </div>

        <div class="mt-10 grid items-start gap-8 md:grid-cols-2">
            @if ($example)
                <section class="cut bg-soft p-5" aria-labelledby="ejemplo">
                    <h2 id="ejemplo" class="eyebrow text-brand">Ejemplo de cuenta por mayor</h2>
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach ($example['lines'] as $line)
                            <li class="flex justify-between gap-3">
                                <span>{{ $line['quantity'] }} × {{ $line['name'] }}</span>
                                <span class="font-mono font-semibold whitespace-nowrap">{{ Money::display($line['total_cents']) }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-3 flex items-baseline justify-between gap-3 border-t-2 border-ink pt-3">
                        <span class="font-display font-bold">Total</span>
                        <span class="font-display text-2xl font-bold">{{ Money::display($example['total_cents']) }}</span>
                    </p>
                    <p class="mt-2 text-xs font-medium text-brand">Ahorras {{ Money::display($example['savings_cents']) }} frente al precio por unidad.</p>
                </section>
            @endif

            <section id="cotizacion" class="scroll-mt-4" aria-labelledby="titulo-cotizacion">
                <h2 id="titulo-cotizacion" class="text-2xl">Pide tu cotización</h2>
                <p class="mt-1 text-sm text-muted">Cuéntanos qué buscas y te respondemos por WhatsApp.</p>
                <x-flash class="mt-3" />

                <form method="POST" action="{{ route('store.wholesale.quote') }}" data-once class="mt-4 space-y-4">
                    @csrf
                    <div>
                        <label for="name" class="eyebrow">Tu nombre o empresa</label>
                        <input id="name" name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="name" class="field mt-1">
                    </div>
                    <div>
                        <label for="phone" class="eyebrow">WhatsApp</label>
                        <input id="phone" type="tel" name="phone" value="{{ old('phone') }}" required maxlength="20" autocomplete="tel" class="field mt-1">
                    </div>
                    <div>
                        <label for="message" class="eyebrow">Qué necesitas</label>
                        <textarea id="message" name="message" rows="4" required maxlength="1000" class="field mt-1" placeholder="Códigos o productos y cantidades">{{ old('message') }}</textarea>
                    </div>
                    <div class="hidden" aria-hidden="true">
                        <label for="website">No llenar</label>
                        <input id="website" name="website" tabindex="-1" autocomplete="off">
                    </div>
                    <button type="submit" class="btn-primary">Pedir cotización</button>
                </form>
            </section>
        </div>

        @if ($popular['cards']->isNotEmpty())
            <section class="mt-12" aria-labelledby="populares">
                <h2 id="populares" class="text-2xl">{{ $popular['from_sales'] ? 'Los más pedidos por mayor' : 'Con stock para pedir por mayor' }}</h2>
                <ul class="mt-5 grid grid-cols-2 gap-x-4 gap-y-8 md:grid-cols-4">
                    @foreach ($popular['cards'] as $product)
                        <li class="flex min-w-0"><x-store.product-card :product="$product" class="w-full" /></li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</x-layouts.store>
