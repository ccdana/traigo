@use('App\Enums\OrderStatus')
@use('App\Support\CustomerOrderStatus')
@use('App\Support\Dates')
@use('App\Support\Money')
@use('Illuminate\Support\Str')

@php
    $total = Money::display(Money::toCents($order->total_bs));
    $current = array_search($order->status->value, array_keys(CustomerOrderStatus::STEPS), true);
    $until = $order->expires_at?->timezone(config('app.timezone'));
    $untilLabel = $until ? ($until->isToday() ? 'las '.$until->format('H:i') : 'las '.$until->format('H:i').' del '.Dates::short($until)) : null;
@endphp

<x-layouts.store :title="'Pedido '.$order->order_number">
    <div class="wrap py-6">
        <x-breadcrumb :trail="['Inicio' => route('store.home'), 'Mis pedidos' => route('store.order.index')]" :current="$order->order_number" />

        <p class="eyebrow mt-3">Pedido {{ $order->order_number }}</p>
        <h1 class="text-4xl">{{ CustomerOrderStatus::title($order->status) }}</h1>
        <p class="mt-2 text-sm">
            {{ CustomerOrderStatus::label($order->status) }}
            @if ($order->status === OrderStatus::PendingPayment && $untilLabel) · reservado hasta {{ $untilLabel }} @endif
        </p>

        <x-flash class="mt-4" />

        @if ($current !== false)
            <ol class="mt-4 grid grid-cols-5 gap-2" aria-label="Avance del pedido">
                @foreach (array_values(CustomerOrderStatus::STEPS) as $index => $label)
                    <li @class(['border-t-4 pt-2 font-mono text-[10px] uppercase sm:text-[11px]', 'border-brand font-semibold text-ink' => $index <= $current, 'border-line text-muted' => $index > $current])
                        @if ($index === $current) aria-current="step" @endif>
                        <span class="sr-only sm:not-sr-only">{{ $label }}</span>
                    </li>
                @endforeach
            </ol>
        @endif

        @if ($rejectReason)
            <p role="alert" class="mt-5 border-l-4 border-danger bg-danger-soft px-4 py-3 text-sm text-danger">No pudimos confirmar tu comprobante: {{ $rejectReason }}. Sube uno nuevo.</p>
        @endif

        @if ($order->status === OrderStatus::Expired)
            <div class="mt-6 bg-warn-soft p-5 text-sm">
                <p>No recibimos el comprobante a tiempo y los productos volvieron a la tienda.</p>
                <p class="mt-2 font-semibold">Si ya pagaste, no vuelvas a pagar: escríbenos con tu comprobante.</p>
                <a href="{{ route('store.home') }}#regalos" class="btn-primary mt-4">Hacer un pedido nuevo</a>
            </div>
        @endif

        <div class="mt-6 grid items-start gap-8 md:grid-cols-2">
            <section class="min-w-0">
                @if ($order->status === OrderStatus::PendingPayment)
                    <h2 class="eyebrow mb-2 text-brand">Paga con QR</h2>
                    <div class="cut flex justify-center bg-soft p-6">
                        @if ($qrUrl)
                            <img src="{{ $qrUrl }}" alt="Código QR de pago de Traigo" class="max-h-80 w-auto bg-white p-2">
                        @else
                            <p class="py-10 text-center text-sm text-muted">El QR no está disponible. Escríbenos por WhatsApp antes de pagar.</p>
                        @endif
                    </div>
                    <p class="mt-3 flex items-baseline justify-between gap-3">
                        <span class="text-sm">Monto a pagar</span>
                        <span class="font-display text-3xl font-bold">{{ $total }}</span>
                    </p>
                @endif

                <h2 class="eyebrow mt-6 mb-2 text-brand">Tu pedido</h2>
                <ul class="border-t border-line text-sm">
                    @foreach ($order->items as $item)
                        <li class="flex justify-between gap-3 border-b border-line py-2">
                            <span>{{ $item->product_name }} × {{ $item->quantity }}</span>
                            <span class="font-mono font-semibold whitespace-nowrap">{{ Money::display(Money::toCents($item->unit_price_bs) * $item->quantity) }}</span>
                        </li>
                    @endforeach
                    <li class="flex justify-between gap-3 py-2 font-display text-lg font-bold"><span>Total</span><span>{{ $total }}</span></li>
                </ul>
                <p class="text-xs text-muted">
                    @if ($order->delivery_type === 'delivery')
                        Entrega: delivery · {{ $order->shipping_address }}. El costo de envío se coordina aparte.
                    @else
                        Entrega: recojo en el punto{{ $pickupAddress ? " · {$pickupAddress}" : '' }}.
                    @endif
                </p>
            </section>

            <section class="min-w-0">
                @if ($order->status->isAwaitingPayment())
                    <h2 class="eyebrow mb-2 text-brand">Qué sigue</h2>
                    <ol>
                        @foreach ([
                            ['Escanea el QR y paga', "Abre la app de tu banco y paga {$total}."],
                            ['Sube tu comprobante', 'Una foto o captura donde se vea el monto y la referencia.'],
                            ['Lo confirmamos', 'Tu pedido pasa a «Pagado» y te avisamos por WhatsApp.'],
                        ] as $index => [$title, $text])
                            <li class="flex gap-4 border-t border-line py-3">
                                <span class="font-display text-2xl font-bold text-brand" aria-hidden="true">{{ $index + 1 }}</span>
                                <span><strong class="font-display">{{ $title }}</strong><span class="block text-sm text-muted">{{ $text }}</span></span>
                            </li>
                        @endforeach
                    </ol>

                    @if ($order->status === OrderStatus::PaymentReview)
                        <p class="bg-soft p-4 text-sm">Ya tenemos tu comprobante. Si subiste uno equivocado, puedes reemplazarlo.</p>
                    @endif

                    <form method="POST" action="{{ route('store.order.proof', $order->public_token) }}" enctype="multipart/form-data" data-once class="mt-4 space-y-4">
                        @csrf
                        <input type="hidden" name="client_uuid" value="{{ (string) Str::uuid() }}">
                        <div>
                            <label for="proof" class="eyebrow">Foto del comprobante (JPG, PNG o WEBP, hasta 5 MB)</label>
                            <input id="proof" type="file" name="proof" accept="image/jpeg,image/png,image/webp" required class="field mt-1">
                        </div>
                        <div>
                            <label for="reference" class="eyebrow">Número de referencia (opcional)</label>
                            <input id="reference" name="reference" value="{{ old('reference') }}" maxlength="60" class="field mt-1">
                        </div>
                        <button type="submit" class="btn-primary">{{ $order->status === OrderStatus::PaymentReview ? 'Reemplazar comprobante' : 'Subir comprobante' }}</button>
                    </form>
                @endif

                <p class="mt-5 text-sm text-muted">Puedes volver cuando quieras desde <a href="{{ route('store.order.index') }}" class="text-brand underline">Mis pedidos</a>.</p>
                @if ($helpUrl)
                    <a href="{{ $helpUrl }}" class="mt-2 inline-block text-sm font-medium text-brand underline" target="_blank" rel="noopener noreferrer">Seguir mi pedido por WhatsApp</a>
                @endif
            </section>
        </div>
    </div>
</x-layouts.store>
