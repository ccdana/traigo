@props(['available'])

<p {{ $attributes->merge(['class' => 'flex items-center gap-1.5 text-xs font-medium '.($available ? 'text-brand' : 'text-muted')]) }}>
    <span @class(['size-2', 'bg-brand' => $available, 'bg-line' => ! $available]) aria-hidden="true"></span>
    {{ $available ? 'Disponible' : 'Agotado' }}
</p>
