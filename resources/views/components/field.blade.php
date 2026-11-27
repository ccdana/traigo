@props(['name', 'label', 'value' => null, 'type' => 'text', 'hint' => null, 'id' => null])

@php($fieldId = $id ?? $name)

<div>
    <label for="{{ $fieldId }}" class="eyebrow">{{ $label }}</label>
    <input id="{{ $fieldId }}" name="{{ $name }}" type="{{ $type }}" value="{{ old($name, $value) }}"
        {{ $attributes->merge(['class' => 'field mt-1']) }}>
    @if ($hint)<p class="mt-1 text-xs text-muted">{{ $hint }}</p>@endif
</div>
