@props(['categories', 'product' => null])

@use('App\Services\Store\StoreCatalog')

{{-- Los datos que se pueden corregir sin autorización. --}}
<x-field name="name" label="Nombre" :value="$product?->name" required maxlength="150" />

<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label for="category_id" class="eyebrow">Categoría</label>
        <select id="category_id" name="category_id" required class="field mt-1">
            <option value="">Elige una</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id_category }}" @selected(old('category_id', $product?->category_id) === $category->id_category)>{{ $category->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="gift_audience" class="eyebrow">Para quién (guía de regalos)</label>
        <select id="gift_audience" name="gift_audience" class="field mt-1">
            <option value="">Sin definir</option>
            @foreach (StoreCatalog::GIFT_AUDIENCES as $value => $label)
                <option value="{{ $value }}" @selected(old('gift_audience', $product?->gift_audience) === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
</div>

<div>
    <label for="description" class="eyebrow">Descripción</label>
    <textarea id="description" name="description" rows="4" maxlength="2000" class="field mt-1">{{ old('description', $product?->description) }}</textarea>
</div>
