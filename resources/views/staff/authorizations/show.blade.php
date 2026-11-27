<x-layouts.staff :title="$item['title']">
    <x-breadcrumb :trail="['Autorizaciones' => route('staff.authorizations.index')]" :current="$item['title']" />
    <h1 class="mt-3 text-3xl">{{ $item['title'] }}</h1>
    <p class="mt-1 text-sm text-muted">Pidió {{ $item['requested_by'] }}</p>
    <x-flash class="mt-4" />

    <div class="mt-4 max-w-2xl space-y-4 bg-white p-5">
        <dl class="text-sm">
            @foreach ($item['changes'] as $change)
                <div class="flex flex-wrap justify-between gap-3 border-b border-line py-2">
                    <dt class="text-muted">{{ $change['label'] }}</dt>
                    <dd class="text-right font-semibold">
                        @if ($change['before'] !== null)<s class="font-normal text-muted">{{ $change['before'] }}</s> → @endif{{ $change['after'] }}
                    </dd>
                </div>
            @endforeach
        </dl>

        <ul class="list-disc space-y-1 pl-5 text-sm text-muted">
            @foreach ($item['checks'] as $check)<li>{{ $check }}</li>@endforeach
        </ul>

        @if ($item['blocked_reason'])
            <p role="alert" class="border-l-4 border-danger bg-danger-soft px-4 py-3 text-sm text-danger">{{ $item['blocked_reason'] }}</p>
        @endif

        <form method="POST" action="{{ route('staff.authorizations.approve', [$type, $id]) }}" data-once>
            @csrf
            <button type="submit" class="btn-primary" @disabled(! $item['can_approve'])>Autorizar</button>
        </form>

        <form method="POST" action="{{ route('staff.authorizations.return', [$type, $id]) }}" data-once class="space-y-3 border-t border-line pt-4">
            @csrf
            <x-field name="reason" label="Motivo para devolverlo" required maxlength="500" />
            <button type="submit" class="btn-soft">Devolver</button>
        </form>
    </div>
</x-layouts.staff>
