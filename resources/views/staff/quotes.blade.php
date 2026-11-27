@use('App\Support\Dates')

<x-layouts.staff title="Cotizaciones">
    <h1 class="text-3xl">Cotizaciones</h1>
    <p class="mt-1 text-sm text-muted">Lo que piden desde la página «Por mayor». Se responde por WhatsApp y aquí se marca.</p>
    <x-flash class="mt-4" />

    <ul class="mt-4 flex flex-wrap gap-2">
        @foreach ($statuses as $value => $label)
            <li><a href="{{ route('staff.quotes.index', ['estado' => $value]) }}" @class([$status === $value ? 'chip-active' : 'chip bg-white'])>{{ $label }}</a></li>
        @endforeach
    </ul>

    <ul class="mt-4 space-y-3">
        @forelse ($quotes as $quote)
            <li class="bg-white p-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-lg">{{ $quote->name }}</h2>
                    <p class="eyebrow">{{ Dates::short($quote->created_at) }}{{ $quote->assigned_name ? " · {$quote->assigned_name}" : '' }}</p>
                </div>
                <p class="mt-1 text-sm">WhatsApp:
                    <a class="font-medium text-brand underline" href="https://wa.me/{{ strlen($digits = preg_replace('/\D/', '', $quote->phone)) === 8 ? '591'.$digits : $digits }}" target="_blank" rel="noopener noreferrer">{{ $quote->phone }}</a>
                </p>
                <p class="mt-2 text-sm whitespace-pre-line text-muted">{{ $quote->message }}</p>

                @if ($quote->status !== 'closed')
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach (['answered' => 'Marcar respondida', 'closed' => 'Cerrar'] as $value => $label)
                            @if ($quote->status !== $value)
                                <form method="POST" action="{{ route('staff.quotes.update', $quote->id_quote_request) }}">
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="status" value="{{ $value }}">
                                    <button type="submit" class="btn-soft py-2">{{ $label }}</button>
                                </form>
                            @endif
                        @endforeach
                    </div>
                @endif
            </li>
        @empty
            <li class="bg-white p-6 text-sm text-muted">No hay cotizaciones en esta lista.</li>
        @endforelse
    </ul>

    {{ $quotes->links('components.pagination') }}
</x-layouts.staff>
